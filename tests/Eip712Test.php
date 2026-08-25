<?php

namespace CoatiPay\Tests;

use PHPUnit\Framework\TestCase;
use CoatiPay\Eip712;

class Eip712Test extends TestCase
{
    public function testBuildAuthorizationTypedData(): void
    {
        $typed = Eip712::buildAuthorizationTypedData(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            [
                'nonce' => '0x' . str_repeat('00', 32),
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
        $this->assertEquals('0x' . str_repeat('00', 32), $msg['nonce']);
    }

    public function testSignAuthorizationMatchesJsSdkReference(): void
    {
        $auth = Eip712::signAuthorization(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            '0x' . str_repeat('11', 32),
            [
                'nonce' => '0x' . str_repeat('00', 32),
                'validAfter' => 0,
                'validBefore' => 2_000_000_000,
            ]
        );

        $expectedSignature =
            '0xedeb072b543902cff56f05d171f505c7bda129cf61c4b94f5905709c822c255e' .
            '49994f31bdb8946811cdb9125c22a87456969d4c847243f0b538fd381483678c1c';

        $this->assertEquals('0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $auth->payer);
        $this->assertEquals(0, $auth->validAfter);
        $this->assertEquals(2_000_000_000, $auth->validBefore);
        $this->assertEquals('0x' . str_repeat('00', 32), $auth->nonce);
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
            '0x' . str_repeat('00', 32),
            '0x' . str_repeat('11', 65),
        );

        $serialized = Eip712::serializeAuthorization($auth);

        $this->assertEquals('0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $serialized['payer']);
        $this->assertEquals('0', $serialized['valid_after']);
        $this->assertEquals('2000000000', $serialized['valid_before']);
        $this->assertEquals('0x' . str_repeat('00', 32), $serialized['nonce']);
        $this->assertEquals('0x' . str_repeat('11', 65), $serialized['signature']);
    }

    public function testGenerateNonce(): void
    {
        $nonce = Eip712::generateNonce();
        $this->assertStringStartsWith('0x', $nonce);
        $this->assertEquals(66, strlen($nonce));
        $this->assertNotEquals(Eip712::generateNonce(), $nonce);
    }

    public function testHashTypedData(): void
    {
        $typed = Eip712::buildAuthorizationTypedData(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1_000_000,
            '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
            'base-sepolia',
            [
                'nonce' => '0x' . str_repeat('00', 32),
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
        $n  = gmp_init('fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141', 16);
        $nh = gmp_div_q($n, 2);

        for ($i = 1; $i <= 12; $i++) {
            $auth = Eip712::signAuthorization(
                '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                1_000_000,
                '0xe2D6EaF23c285E827f37dC5Ec05fFfD860dBE0e1',
                'base-sepolia',
                '0x' . str_repeat('11', 32),
                [
                    'nonce' => '0x' . str_pad(dechex($i), 64, '0', STR_PAD_LEFT),
                    'validAfter' => 0,
                    'validBefore' => 2_000_000_000,
                ]
            );
            $s = gmp_init(substr($auth->signature, 2 + 64, 64), 16);
            $this->assertLessThanOrEqual(
                0,
                gmp_cmp($s, $nh),
                "signature for nonce {$i} has high-s (would revert on-chain)"
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
