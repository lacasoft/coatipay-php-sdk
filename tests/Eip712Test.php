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

    /** La cartera del comercio del cobro (`merchant_wallet`): entra en el nonce (ADR-009). */
    private const MERCHANT = '0x2222222222222222222222222222222222222222';

    public function testBuildAuthorizationTypedData(): void
    {
        $typed = Eip712::buildAuthorizationTypedData(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            self::INTENT_ID,
            self::MERCHANT,
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
        $this->assertEquals(Eip712::intentIdOf(self::MERCHANT, self::INTENT_ID), $msg['nonce']);
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
            self::MERCHANT,
        );

        // Y la derivación ocurre dentro: al integrador se le pide el `pi_…` que
        // ya tiene, no un keccak que podría calcular mal sin enterarse hasta
        // que la liquidación falle.
        $this->assertEquals(Eip712::intentIdOf(self::MERCHANT, 'pi_abc123'), $typed['message']['nonce']);
    }

    public function testIntentRefEsKeccakDelTextoUtf8(): void
    {
        // Vector fijo, el mismo que produce el SDK de JavaScript con
        // `intentRef('pi_test_001')`. Si esta constante cambia, las dos
        // implementaciones han dejado de derivar lo mismo.
        $this->assertEquals(
            '0x4ce0f8f8b3c1ae4648b44946a8d3555a9a04f476786f1b4260923079b1a0253c',
            Eip712::intentRef('pi_test_001')
        );
        // Es literalmente keccak256 sobre los bytes utf-8 del id.
        $this->assertEquals(
            '0x' . \kornrunner\Keccak::hash('pi_test_001', 256),
            Eip712::intentRef('pi_test_001')
        );
    }

    public function testIntentIdOfLlevaLaCarteraDelComercio(): void
    {
        // Vector fijo, el que calculan el paquete del protocolo, el SDK de
        // JavaScript y el hub: keccak256(abi.encode(merchant, intentRef(id))).
        $this->assertEquals(
            '0x49b48a490b8527d2af148b118ed2eba075935d53fc90962ecfca1b29378ba541',
            Eip712::intentIdOf(self::MERCHANT, 'pi_abc123')
        );
        // Otra cartera, otro nonce: la firma del pagador fija a quién paga.
        $this->assertNotEquals(
            Eip712::intentIdOf(self::MERCHANT, 'pi_abc123'),
            Eip712::intentIdOf('0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1', 'pi_abc123')
        );
    }

    public function testRechazaUnaCarteraMalFormadaOCero(): void
    {
        foreach (['', '0x1234', '0x' . str_repeat('00', 20)] as $mala) {
            try {
                Eip712::intentIdOf($mala, 'pi_abc123');
                $this->fail("aceptó la cartera {$mala}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('merchant', $e->getMessage());
            }
        }
    }

    public function testLaFirmaDeAntesFallaEnVezDeFirmarMal(): void
    {
        // Las dos formas de llamar de 0.1.x fallan en voz alta, en vez de firmar
        // un nonce que el hub nuevo no aceptaría: sin opciones faltan
        // argumentos; con opciones, el array cae donde va la clave privada.
        $antes = [
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            self::INTENT_ID,
            '0x' . str_repeat('11', 32),
        ];
        foreach ([$antes, [...$antes, ['validAfter' => 0]]] as $argumentos) {
            try {
                Eip712::signAuthorization(...$argumentos);
                $this->fail('firmó con la forma de llamar de 0.1.x');
            } catch (\TypeError $e) {
                $this->addToAssertionCount(1);
            }
        }
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
            self::MERCHANT,
        );
    }

    public function testRechazaUnBytes32YaDerivado(): void
    {
        // Salvavidas de migración: la versión anterior de este SDK pedía el
        // bytes32. Quien no se entere del cambio hashearía dos veces y firmaría
        // un nonce que no corresponde a ningún intent — y no se enteraría hasta
        // liquidar. Un id de la API es `pi_…`, nunca 0x + 64 hex.
        $this->expectException(\InvalidArgumentException::class);

        Eip712::intentRef('0x' . str_repeat('be', 32));
    }

    public function testSignAuthorizationMatchesJsSdkReference(): void
    {
        // Vector cruzado con el SDK de JavaScript: prueba de que las dos
        // implementaciones firman byte a byte lo mismo. Regenerado tras el
        // cambio de entrada — ahora el intent entra como texto `pi_…` y el
        // nonce sale de derivarlo, así que el digest firmado es otro.
        //
        // Generado con @lacasoft/coatipay-sdk 0.2.0 (ADR-009: el nonce lleva la
        // cartera del comercio):
        //   signReceiveAuthorization({
        //     payer: '0xaaaa…aa', amount: 1_000_000n,
        //     settlementHub: '0xe2D6…E0e1', chain: 'base-sepolia',
        //     intentId: 'pi_test_001', merchant: '0x2222…22',
        //     validAfter: 0n, validBefore: 2_000_000_000n,
        //   }, '0x1111…11')
        $intentId = self::INTENT_ID;

        $auth = Eip712::signAuthorization(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            $intentId,
            self::MERCHANT,
            '0x' . str_repeat('11', 32),
            [
                'validAfter' => 0,
                'validBefore' => 2_000_000_000,
            ]
        );

        $expectedNonce = '0xe6ac3357ad9549c03bf4e428efdd5f46f4c2b3ec9a2e84c67fbc4398a295fde5';
        $expectedSignature =
            '0x2c3cf9f94a42eb5e60141e1ab005c6fc5d0e4709ceb1a33892042b658ab9b165' .
            '3d4df79dc2b4637b755b4e7e614cc36b3b386ceed4c5d6e9914e7698d462984a1c';

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
            Eip712::intentIdOf(self::MERCHANT, self::INTENT_ID),
            '0x' . str_repeat('11', 65),
        );

        $serialized = Eip712::serializeAuthorization($auth);

        $this->assertEquals('0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $serialized['payer']);
        $this->assertEquals('0', $serialized['valid_after']);
        $this->assertEquals('2000000000', $serialized['valid_before']);
        // El wire format sigue mandando el campo `nonce`, con el intent dentro.
        $this->assertEquals(Eip712::intentIdOf(self::MERCHANT, self::INTENT_ID), $serialized['nonce']);
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
            self::MERCHANT,
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
                self::MERCHANT,
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
