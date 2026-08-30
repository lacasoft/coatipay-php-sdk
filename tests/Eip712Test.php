<?php

namespace CoatiPay\Tests;

use PHPUnit\Framework\TestCase;
use CoatiPay\Eip712;

class Eip712Test extends TestCase
{
    /**
     * Identificador del intent tal cual lo devuelve la API. De aquí sale el
     * nonce del mensaje firmado, derivado dentro del SDK: el contrato exige
     * `nonce == keccak256(utf8(intentId))`.
     */
    private const INTENT_ID = 'pi_test_001';

    /** Otro intent cualquiera, para comprobar que una firma no sirve para los dos. */
    private const OTRO_INTENT_ID = 'pi_otro_002';

    public function testBuildAuthorizationTypedData(): void
    {
        $typed = Eip712::buildAuthorizationTypedData(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            self::INTENT_ID,
            [
                'validAfter' => 0,
                'validBefore' => 2_000_000_000,
            ]
        );

        $this->assertEquals('USDC', $typed['domain']['name']);
        $this->assertEquals('2', $typed['domain']['version']);
        $this->assertEquals(84532, $typed['domain']['chainId']);
        $this->assertEquals('0x036CbD53842c5426634e7929541eC2318f3dCF7e', $typed['domain']['verifyingContract']);
        $this->assertEquals('ReceiveWithAuthorization', $typed['primaryType']);

        $msg = $typed['message'];
        $this->assertEquals('0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $msg['from']);
        $this->assertEquals('0xe2d6eaf23c285e827f37dc5ec05fffd860dbe0e1', $msg['to']);
        $this->assertEquals(1_000_000, $msg['value']);
        $this->assertEquals(0, $msg['validAfter']);
        $this->assertEquals(2_000_000_000, $msg['validBefore']);
        // El campo del mensaje se sigue llamando `nonce` (lo fija ERC-3009);
        // lo que cambia es de dónde sale su valor: del id textual, hasheado.
        $this->assertEquals(Eip712::intentIdToBytes32(self::INTENT_ID), $msg['nonce']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $msg['nonce']);
    }

    public function testNonceEsLaDerivacionDelIntentId(): void
    {
        // La atadura, explícita: el nonce del mensaje ES el intent que se paga.
        // Sin ella, el nodeit —quien envía la transacción, la parte no confiable—
        // podía tomar esta firma y aplicarla a otro intent para quedarse el pago.
        $typed = Eip712::buildAuthorizationTypedData(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            5_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            'pi_abc123',
        );

        // Y la derivación ocurre dentro: al integrador se le pide el `pi_…` que
        // ya tiene, no un keccak que podría calcular mal sin enterarse hasta
        // que la liquidación falle.
        $this->assertEquals(Eip712::intentIdToBytes32('pi_abc123'), $typed['message']['nonce']);
    }

    public function testIntentIdToBytes32EsKeccakDelTextoUtf8(): void
    {
        // Vector fijo, el mismo que produce el SDK de JavaScript con
        // `intentIdToBytes32('pi_test_001')`. Si esta constante cambia, las dos
        // implementaciones han dejado de derivar el mismo nonce.
        $this->assertEquals(
            '0x4ce0f8f8b3c1ae4648b44946a8d3555a9a04f476786f1b4260923079b1a0253c',
            Eip712::intentIdToBytes32('pi_test_001')
        );
        // Es literalmente keccak256 sobre los bytes utf-8 del id.
        $this->assertEquals(
            '0x' . \kornrunner\Keccak::hash('pi_test_001', 256),
            Eip712::intentIdToBytes32('pi_test_001')
        );
    }

    public function testFirmaAtadaAlIntentTambienAlFirmar(): void
    {
        // Lo mismo, pero de punta a punta: la autorización firmada arrastra el
        // intent en su nonce, y firmar el mismo pago para otro intent produce
        // otra firma. Es decir: una firma sirve para un solo intent.
        $auth = Eip712::signAuthorization(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            self::INTENT_ID,
            '0x' . str_repeat('11', 32),
            ['validAfter' => 0, 'validBefore' => 2_000_000_000],
        );

        $otra = Eip712::signAuthorization(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            self::OTRO_INTENT_ID,
            '0x' . str_repeat('11', 32),
            ['validAfter' => 0, 'validBefore' => 2_000_000_000],
        );

        $this->assertEquals(Eip712::intentIdToBytes32(self::INTENT_ID), $auth->nonce);
        $this->assertEquals(Eip712::intentIdToBytes32(self::OTRO_INTENT_ID), $otra->nonce);
        $this->assertNotEquals($auth->nonce, $otra->nonce);
        $this->assertNotEquals($auth->signature, $otra->signature);
    }

    public function testRechazaUnIntentIdVacio(): void
    {
        // Un id vacío hashea a un bytes32 perfectamente válido, y la firma
        // quedaría atada a un intent que no existe. Es el único caso en el que
        // el texto de entrada no puede ser un intent de verdad.
        $this->expectException(\InvalidArgumentException::class);

        Eip712::buildAuthorizationTypedData(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            '',
        );
    }

    public function testRechazaUnBytes32YaDerivado(): void
    {
        // Salvavidas de migración: la versión anterior de este SDK pedía el
        // bytes32. Quien no se entere del cambio hashearía dos veces y firmaría
        // un nonce que no corresponde a ningún intent — y no se enteraría hasta
        // liquidar. Un id de la API es `pi_…`, nunca 0x + 64 hex.
        $this->expectException(\InvalidArgumentException::class);

        Eip712::intentIdToBytes32('0x' . str_repeat('be', 32));
    }

    public function testSignAuthorizationMatchesJsSdkReference(): void
    {
        // Vector cruzado con el SDK de JavaScript: prueba de que las dos
        // implementaciones firman byte a byte lo mismo. Regenerado tras el
        // cambio de entrada — ahora el intent entra como texto `pi_…` y el
        // nonce sale de derivarlo, así que el digest firmado es otro.
        //
        // Generado con @lacasoft/coatipay-sdk:
        //   signReceiveAuthorization({
        //     payer: '0xaaaa…aa', amount: 1_000_000n,
        //     settlementHub: '0xe2D6…E0e1', chain: 'base-sepolia',
        //     intentId: 'pi_test_001', validAfter: 0n, validBefore: 2_000_000_000n,
        //   }, '0x1111…11')
        $intentId = self::INTENT_ID;

        $auth = Eip712::signAuthorization(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            $intentId,
            '0x' . str_repeat('11', 32),
            [
                'validAfter' => 0,
                'validBefore' => 2_000_000_000,
            ]
        );

        $expectedNonce = '0x4ce0f8f8b3c1ae4648b44946a8d3555a9a04f476786f1b4260923079b1a0253c';
        $expectedSignature =
            '0x6aca1f4b2536c4bcceec0baf128257e8740b79f9461c555fb51d329424515770' .
            '03d4762e713650a8803af2b34c613f16ba40200f661c2c4ace469958b58963821c';

        $this->assertEquals('0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $auth->payer);
        $this->assertEquals(0, $auth->validAfter);
        $this->assertEquals(2_000_000_000, $auth->validBefore);
        $this->assertEquals($expectedNonce, $auth->nonce);
        $this->assertEquals($expectedSignature, $auth->signature);
    }

    public function testSplitSignature(): void
    {
        $sig = '0x' . str_repeat('11', 32) . str_repeat('22', 32) . '1c';
        $parts = Eip712::splitSignature($sig);

        $this->assertEquals('28', $parts['v']);
        $this->assertEquals('0x' . str_repeat('11', 32), $parts['r']);
        $this->assertEquals('0x' . str_repeat('22', 32), $parts['s']);
    }

    public function testSplitSignatureNormalizesLowV(): void
    {
        $sig = '0x' . str_repeat('11', 32) . str_repeat('22', 32) . '00';
        $parts = Eip712::splitSignature($sig);

        $this->assertEquals('27', $parts['v']);
    }

    public function testSerializeAuthorization(): void
    {
        $auth = new \CoatiPay\SignedAuthorization(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            0,
            2_000_000_000,
            Eip712::intentIdToBytes32(self::INTENT_ID),
            '0x' . str_repeat('11', 65),
        );

        $serialized = Eip712::serializeAuthorization($auth);

        $this->assertEquals('0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $serialized['payer']);
        $this->assertEquals('0', $serialized['valid_after']);
        $this->assertEquals('2000000000', $serialized['valid_before']);
        // El wire format sigue mandando el campo `nonce`, con el intent dentro.
        $this->assertEquals(Eip712::intentIdToBytes32(self::INTENT_ID), $serialized['nonce']);
        $this->assertEquals('0x' . str_repeat('11', 65), $serialized['signature']);
    }

    public function testHashTypedData(): void
    {
        $typed = Eip712::buildAuthorizationTypedData(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            self::INTENT_ID,
            [
                'validAfter' => 0,
                'validBefore' => 2_000_000_000,
            ]
        );

        $digest = Eip712::hashTypedData($typed);
        $this->assertStringStartsWith('0x', $digest);
        $this->assertEquals(66, strlen($digest));
    }

    public function testSignaturesAreAlwaysLowS(): void
    {
        // USDC's OpenZeppelin ECDSA rejects high-s signatures (EIP-2). Without
        // canonical signing ~50% of nonces produce high-s → on-chain revert.
        // Cada vuelta firma un intent distinto, que es lo que mueve el nonce.
        $n  = gmp_init('fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141', 16);
        $nh = gmp_div_q($n, 2);

        for ($i = 1; $i <= 12; $i++) {
            $intentId = "pi_low_s_{$i}";
            $auth = Eip712::signAuthorization(
                '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                1_000_000,
                '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
                'base-sepolia',
                $intentId,
                '0x' . str_repeat('11', 32),
                [
                    'validAfter' => 0,
                    'validBefore' => 2_000_000_000,
                ]
            );
            $s = gmp_init(substr($auth->signature, 2 + 64, 64), 16);
            $this->assertLessThanOrEqual(
                0,
                gmp_cmp($s, $nh),
                "signature for intent {$i} has high-s (would revert on-chain)"
            );
        }
    }

    public function testTypehashesMatchKeccak(): void
    {
        // Guard the hardcoded typehash constants against drift.
        $domainType = 'EIP712Domain(string name,string version,uint256 chainId,address verifyingContract)';
        $receiveType = 'ReceiveWithAuthorization(address from,address to,uint256 value,uint256 validAfter,uint256 validBefore,bytes32 nonce)';

        $this->assertEquals(
            '8b73c3c69bb8fe3d512ecc4cf759cc79239f7b179b0ffacaa9a75d522b39400f',
            \kornrunner\Keccak::hash($domainType, 256)
        );
        $this->assertEquals(
            'd099cc98ef71107a616c4f0f941f04c322d8e254fe26b3c6668db87aae413de8',
            \kornrunner\Keccak::hash($receiveType, 256)
        );
    }
}
