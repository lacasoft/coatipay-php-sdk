<?php

declare(strict_types=1);

namespace CoatiPay;

/**
 * A signed ERC-3009 ReceiveWithAuthorization message, ready to submit.
 */
class SignedAuthorization
{
    /**
     * @param string $payer       Dirección del pagador (el `from` de la transferencia USDC).
     * @param int    $validAfter  Inicio de la ventana de validez, en segundos Unix.
     * @param int    $validBefore Fin de la ventana de validez, en segundos Unix.
     * @param string $nonce       Nonce de la autorización, hex de 32 bytes. **Es el `intentId`**:
     *                            el contrato exige `nonce == intentId` para que una firma no pueda
     *                            aplicarse a otro intent. Ya no es un valor aleatorio.
     * @param string $signature   Firma cruda en hex. Para una EOA es el blob ECDSA de 65 bytes;
     *                            para una smart wallet, la firma ERC-1271. USDC valida ambas.
     */
    public function __construct(
        public string $payer,
        public int $validAfter,
        public int $validBefore,
        public string $nonce,
        public string $signature,
    ) {}
}
