<?php

namespace EzEcommerce\Api\Http;

use EzEcommerce\Cart\Exceptions\CartTotalsChangedException;
use EzEcommerce\Cart\Exceptions\CartVersionConflictException;
use EzEcommerce\Core\Exceptions\IdempotencyConflictException;
use EzEcommerce\Core\Exceptions\IdempotencyPayloadMismatchException;
use EzEcommerce\Payments\Exceptions\PaymentOperationNotSupported;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RegistersCommerceApiExceptions
{
    public static function register(): void
    {
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        $handler->renderable(function (CartVersionConflictException $e, Request $request): ?Response {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'cart_version_conflict',
                'cart_version' => $e->cartVersion,
            ], 409);
        });

        $handler->renderable(function (CartTotalsChangedException $e, Request $request): ?Response {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'totals_changed',
            ], 409);
        });

        $handler->renderable(function (IdempotencyConflictException $e, Request $request): ?Response {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'idempotency_conflict',
            ], 409);
        });

        $handler->renderable(function (IdempotencyPayloadMismatchException $e, Request $request): ?Response {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'idempotency_payload_mismatch',
            ], 422);
        });

        $handler->renderable(function (PaymentOperationNotSupported $e, Request $request): ?Response {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'payment_operation_not_supported',
            ], 422);
        });

        $handler->renderable(function (InvalidArgumentException $e, Request $request): ?Response {
            if (! $request->is('api/ez-commerce/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'invalid_argument',
            ], 422);
        });

        $handler->renderable(function (Throwable $e, Request $request): ?Response {
            if (! $request->is('api/ez-commerce/*')) {
                return null;
            }

            if ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'in progress')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'operation_in_progress',
                ], 409);
            }

            return null;
        });
    }
}
