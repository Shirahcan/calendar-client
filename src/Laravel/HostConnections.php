<?php

namespace Shirahcan\CalendarClient\Laravel;

use Shirahcan\CalendarClient\CalendarClient;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceException;

/**
 * A person's connected calendars, as calendar-service holds them. The connection is the PERSON's
 * (connect Google once, every product uses it), so a product keeps no copy and asks here.
 * Registered `scoped`: one call per request or job, however many readers ask.
 */
class HostConnections
{
    /** @var array<string, list<array>|null> host auth id => the service's rows, this request only */
    private array $rows = [];

    /**
     * The person's connections (`provider`, `account_email`, `status` active|needs_reauth,
     * `last_error`, `reconnect_recommended`, ...). Null when the service could not say: show
     * "cannot check", never "not connected".
     *
     * @return list<array>|null
     */
    public function for(string $hostAuthId): ?array
    {
        if ($hostAuthId === '') {
            return [];
        }
        if (array_key_exists($hostAuthId, $this->rows)) {
            return $this->rows[$hostAuthId];
        }

        try {
            $rows = array_values(app(CalendarClient::class)->connections($hostAuthId));
        } catch (CalendarServiceException $e) {
            if (function_exists('report')) {
                report($e);
            }
            $rows = null;
        }

        return $this->rows[$hostAuthId] = $rows;
    }

    /**
     * The connections the person must reconnect: signed out at the provider (`needs_reauth`), or a
     * grant the service recommends redoing (e.g. no free/busy scope). Null when it could not say.
     *
     * @return list<array>|null
     */
    public function needingReconnect(string $hostAuthId): ?array
    {
        $rows = $this->for($hostAuthId);

        return $rows === null ? null : array_values(array_filter($rows, [self::class, 'needsReconnect']));
    }

    public static function needsReconnect(array $connection): bool
    {
        return ($connection['status'] ?? null) === 'needs_reauth'
            || ($connection['reconnect_recommended'] ?? false) === true;
    }
}
