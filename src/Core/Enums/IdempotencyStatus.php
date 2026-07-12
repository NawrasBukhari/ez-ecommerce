<?php

namespace EzEcommerce\Core\Enums;

enum IdempotencyStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case FailedRetryable = 'failed_retryable';
    case FailedTerminal = 'failed_terminal';
}
