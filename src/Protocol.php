<?php

declare(strict_types=1);

namespace KevStudios\Beacon;

/**
 * Beacon wire-protocol constants — PHP mirror of @makfly/beacon-protocol.
 * Keep in sync with protocol/src/constants.ts.
 */
final class Protocol
{
    public const VERSION = 'v0';

    // Entry point attribute keys.
    public const ATTR_ENTRY_POINT_TYPE = 'beacon.entry_point.type';
    public const ATTR_ENTRY_POINT_VALUE = 'beacon.entry_point.value';
    public const ATTR_HANDLER_IDENTIFIER = 'beacon.entry_point.handler.identifier';
    public const ATTR_HANDLER_NAME = 'beacon.entry_point.handler.name';
    public const ATTR_HANDLER_TYPE = 'beacon.entry_point.handler.type';
    public const ATTR_SPAN_TYPE = 'beacon.span_type';
    public const ATTR_SPAN_EVENT_TYPE = 'beacon.span_event_type';

    // Entry point types.
    public const ENTRY_WEB = 'web';
    public const ENTRY_CLI = 'cli';
    public const ENTRY_QUEUE = 'queue';

    // Handler-type prefixes (Symfony).
    public const HANDLER_SYMFONY_CONTROLLER = 'symfony_controller';
    public const HANDLER_SYMFONY_COMMAND = 'symfony_command';
    public const HANDLER_MESSENGER = 'messenger_handler';

    // Span types.
    public const SPAN_HTTP_REQUEST = 'http_request';
    public const SPAN_HTTP_CLIENT = 'http_client';
    public const SPAN_DB_QUERY = 'db_query';
    public const SPAN_DB_TRANSACTION = 'db_transaction';
    public const SPAN_CACHE = 'cache';
    public const SPAN_MESSENGER = 'messenger_message';
    public const SPAN_CONSOLE = 'console_command';
    public const SPAN_RENDER = 'render';
    public const SPAN_MIDDLEWARE = 'middleware';

    // OTel span status codes.
    public const STATUS_UNSET = 0;
    public const STATUS_OK = 1;
    public const STATUS_ERROR = 2;

    // OTel severity numbers.
    public const SEVERITY = [
        'TRACE' => 1,
        'DEBUG' => 5,
        'INFO' => 9,
        'WARN' => 13,
        'ERROR' => 17,
        'FATAL' => 21,
    ];
}
