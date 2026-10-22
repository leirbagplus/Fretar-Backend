<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Shipping;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /**
     * Armazena APENAS o token fornecido pelo Stripe.
     * Nunca armazenar numero de cartao, cvv, validade ou dados completos.
     */
    public function salvarMetodo(User $usuario, array $dados): PaymentMethod
    {
        return PaymentMethod::create([
            'user_id' => $usuario->user_id,
            'stripe_customer_id' => $dados['stripe_customer_id'] ?? 'cus_mock_'.Str::uuid(),
            'stripe_payment_method_id' => $dados['stripe_payment_method_id'],
            'type' => $dados['type'],
            'last_4_digits' => $dados['last_4_digits'] ?? null,
            'card_brand' => $dados['card_brand'] ?? null,
        ]);
    }

    /**
     * Criar um PaymentIntent (Stripe) para o shipping. Suporta modo mock
     * para desenvolvimento (pi_mock_*). Pagamentos podem possuir multiplas
     * tentativas para o mesmo shipping.
     */
    public function criarIntent(Shipping $shipping, PaymentMethod $metodo): Payment
    {
        if ($metodo->user_id !== $shipping->user_id) {
            abort(403, 'Método de pagamento não pertence a você.');
        }

        if ((float) $shipping->value <= 0) {
            throw ValidationException::withMessages(['value' => 'Valor inválido.']);
        }

        $payment = DB::transaction(function () use ($shipping, $metodo): Payment {
            $payment = Payment::create([
                'shipping_id' => $shipping->shipping_id,
                'payment_method_id' => $metodo->payment_method_id,
                'value' => $shipping->value,
                'payment_status' => PaymentStatus::Pending,
            ]);

            $payment->update([
                'stripe_payment_intent_id' => $this->criarIntentStripe($payment, $metodo),
                'payment_status' => PaymentStatus::Processing,
            ]);

            return $payment;
        });

        return $payment->refresh();
    }

    /**
     * Confirma um pagamento (mock ou consulta real ao Stripe).
     * Em producao idealmente via webhook (STRIPE_WEBHOOK_SECRET).
     */
    public function confirmar(Payment $payment, PaymentMethod $metodo): Payment
    {
        if ($payment->payment_status === PaymentStatus::Succeeded) {
            return $payment;
        }

        if ($this->usarMock()) {
            $payment->update([
                'payment_status' => PaymentStatus::Succeeded,
                'paid_at' => now(),
            ]);

            return $payment->refresh();
        }

        if (! $payment->stripe_payment_intent_id) {
            throw ValidationException::withMessages([
                'payment' => 'Pagamento sem PaymentIntent válido.',
            ]);
        }

        // Caminho real: consulta o status do PaymentIntent no Stripe.
        $cliente = $this->clienteStripe();
        $intent = $cliente->paymentIntents->retrieve($payment->stripe_payment_intent_id);

        $novoStatus = match ($intent->status) {
            'succeeded' => PaymentStatus::Succeeded,
            'processing' => PaymentStatus::Processing,
            default => PaymentStatus::Failed,
        };

        $payment->update([
            'payment_status' => $novoStatus,
            'paid_at' => $novoStatus === PaymentStatus::Succeeded ? now() : $payment->paid_at,
        ]);

        return $payment->refresh();
    }

    private function criarIntentStripe(Payment $payment, PaymentMethod $metodo): string
    {
        if ($this->usarMock()) {
            return 'pi_mock_'.Str::uuid();
        }

        $inteira = (int) round((float) $payment->value * 100);

        $intent = $this->clienteStripe()->paymentIntents->create([
            'amount' => $inteira,
            'currency' => 'brl',
            'payment_method' => $metodo->stripe_payment_method_id,
            'confirmation_method' => 'manual',
            'confirm' => false,
        ]);

        return $intent->id;
    }

    private function usarMock(): bool
    {
        $secret = config('services.stripe.secret');

        return app()->environment(['local', 'testing'])
            && ((bool) config('services.stripe.mock_enabled')
                || ! $secret
                || ! class_exists(\Stripe\StripeClient::class));
    }

    private function clienteStripe(): \Stripe\StripeClient
    {
        return new \Stripe\StripeClient(config('services.stripe.secret'));
    }
}