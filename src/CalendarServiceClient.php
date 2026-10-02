<?php

namespace Shirahcan\CalendarClient;

use DateTimeInterface;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Shirahcan\CalendarClient\Exceptions\CalendarNotFound;
use Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceException;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceUnavailable;
use Shirahcan\CalendarClient\Exceptions\HoldExpired;
use Shirahcan\CalendarClient\Exceptions\SlotUnavailable;

/**
 * Talks to calendar-service over loopback.
 *
 * ⚠ IT OWNS NO TIME. No slot engine, no busy cache, no calendar tokens "as a fallback".
 * A client that could book on its own when the service is down would recreate the three
 * engines this programme exists to end and double-book people across products. If the
 * service is unreachable that is an outage to report (CalendarServiceUnavailable).
 */
class CalendarServiceClient implements CalendarClient
{
    public function __construct(
        private string $baseUrl,
        private string $trustKey,
        private int $timeout = 10,
        private ?Guzzle $http = null,
    ) {
        $this->http ??= new Guzzle(['base_uri' => rtrim($this->baseUrl, '/').'/', 'timeout' => $this->timeout]);
    }

    public function upsertHost(string $authId, ?string $name = null, ?string $zone = null): array
    {
        return $this->send('PUT', 'api/v1/hosts/'.rawurlencode($authId), array_filter(['name' => $name, 'zone' => $zone], fn ($v) => $v !== null));
    }

    public function upsertSchedule(string $ref, ?string $hostAuthId, array $spec): array
    {
        return $this->send('PUT', 'api/v1/schedules/'.rawurlencode($ref), ['host' => $hostAuthId, 'spec' => $spec]);
    }

    public function schedule(string $ref): array
    {
        return $this->send('GET', 'api/v1/schedules/'.rawurlencode($ref));
    }

    public function deleteSchedule(string $ref): void
    {
        try {
            $this->send('DELETE', 'api/v1/schedules/'.rawurlencode($ref));
        } catch (CalendarNotFound) {
            // Already gone: the outcome the caller asked for.
        }
    }

    public function upsertBookingType(string $ref, array $definition): array
    {
        return $this->send('PUT', 'api/v1/booking-types/'.rawurlencode($ref), $definition);
    }

    public function slots(string $bookingTypeRef, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $data = $this->send('GET', 'api/v1/booking-types/'.rawurlencode($bookingTypeRef).'/slots', [
            'from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM),
        ]);

        return array_map(fn (array $s) => Slot::fromArray($s), (array) ($data['slots'] ?? []));
    }

    public function previewSlots(array $spec, array $rules, DateTimeInterface $from, DateTimeInterface $to, ?string $hostAuthId = null): array
    {
        $data = $this->send('POST', 'api/v1/slots/preview', array_filter([
            'spec' => $spec, 'rules' => $rules, 'from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM), 'host' => $hostAuthId,
        ], fn ($v) => $v !== null));

        return array_map(fn (array $s) => Slot::fromArray($s), (array) ($data['slots'] ?? []));
    }

    public function hold(string $bookingTypeRef, DateTimeInterface $start, string $idempotencyKey, array $details = []): Booking
    {
        return Booking::fromArray($this->send('POST', 'api/v1/bookings', [
            'booking_type' => $bookingTypeRef, 'start' => $start->format(DATE_ATOM), 'idempotency_key' => $idempotencyKey,
        ] + $details));
    }

    public function extendHold(string $bookingId, int $seconds): Booking
    {
        return $this->bookingAction($bookingId, 'extend', ['seconds' => $seconds]);
    }

    public function release(string $bookingId, ?string $actor = null): Booking
    {
        return $this->bookingAction($bookingId, 'release', ['actor' => $actor]);
    }

    public function confirm(string $bookingId, ?string $actor = null): Booking
    {
        return $this->bookingAction($bookingId, 'confirm', ['actor' => $actor]);
    }

    public function approve(string $bookingId, ?string $actor = null): Booking
    {
        return $this->bookingAction($bookingId, 'approve', ['actor' => $actor]);
    }

    public function decline(string $bookingId, ?string $actor = null, ?string $reason = null): Booking
    {
        return $this->bookingAction($bookingId, 'decline', ['actor' => $actor, 'reason' => $reason]);
    }

    public function reschedule(string $bookingId, DateTimeInterface $start, ?string $actor = null, ?string $reason = null): Booking
    {
        return $this->bookingAction($bookingId, 'reschedule', ['start' => $start->format(DATE_ATOM), 'actor' => $actor, 'reason' => $reason]);
    }

    public function propose(string $bookingId, DateTimeInterface $start, string $proposedBy, ?string $actor = null, ?DateTimeInterface $expiresAt = null): array
    {
        return $this->send('POST', 'api/v1/bookings/'.rawurlencode($bookingId).'/proposals', array_filter([
            'start' => $start->format(DATE_ATOM), 'proposed_by' => $proposedBy, 'actor' => $actor, 'expires_at' => $expiresAt?->format(DATE_ATOM),
        ], fn ($v) => $v !== null));
    }

    public function acceptProposal(string $proposalId, ?string $actor = null): Booking
    {
        return Booking::fromArray($this->send('POST', 'api/v1/proposals/'.rawurlencode($proposalId).'/accept', array_filter(['actor' => $actor])));
    }

    public function declineProposal(string $proposalId, ?string $actor = null): array
    {
        return $this->send('POST', 'api/v1/proposals/'.rawurlencode($proposalId).'/decline', array_filter(['actor' => $actor]));
    }

    public function cancel(string $bookingId, string $by, ?string $actor = null, ?string $reason = null): Booking
    {
        return $this->bookingAction($bookingId, 'cancel', ['by' => $by, 'actor' => $actor, 'reason' => $reason]);
    }

    public function markAttendance(string $bookingId, int $participantId, string $status, ?string $actor = null): array
    {
        return $this->send('POST', 'api/v1/bookings/'.rawurlencode($bookingId).'/attendance', array_filter([
            'participant_id' => $participantId, 'status' => $status, 'actor' => $actor,
        ], fn ($v) => $v !== null));
    }

    public function createMeeting(array $hostAuthIds, DateTimeInterface $start, DateTimeInterface $end, string $idempotencyKey, array $details = []): Booking
    {
        return Booking::fromArray($this->send('POST', 'api/v1/meetings', [
            'hosts' => array_values($hostAuthIds), 'start' => $start->format(DATE_ATOM), 'end' => $end->format(DATE_ATOM), 'idempotency_key' => $idempotencyKey,
        ] + $details));
    }

    public function booking(string $bookingId): Booking
    {
        return Booking::fromArray($this->send('GET', 'api/v1/bookings/'.rawurlencode($bookingId)));
    }

    public function events(string $hostAuthId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $data = $this->send('GET', 'api/v1/events', ['host' => $hostAuthId, 'from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)]);

        return ['events' => (array) ($data['events'] ?? []), 'stale_external' => (bool) ($data['stale_external'] ?? false)];
    }

    public function connectUrl(string $hostAuthId, string $provider, string $returnUrl): string
    {
        return (string) $this->send('POST', 'api/v1/connections/connect-url', ['host' => $hostAuthId, 'provider' => $provider, 'return_url' => $returnUrl])['url'];
    }

    public function connections(string $hostAuthId): array
    {
        return array_values($this->send('GET', 'api/v1/hosts/'.rawurlencode($hostAuthId).'/connections'));
    }

    public function updateConnection(int $connectionId, string $hostAuthId, ?array $busyCalendars = null, ?string $writeCalendar = null): array
    {
        return $this->send('PUT', "api/v1/connections/{$connectionId}", array_filter([
            'host' => $hostAuthId, 'busy_calendars' => $busyCalendars, 'write_calendar' => $writeCalendar,
        ], fn ($v) => $v !== null));
    }

    public function disconnect(int $connectionId, string $hostAuthId): array
    {
        return $this->send('DELETE', "api/v1/connections/{$connectionId}", ['host' => $hostAuthId]);
    }

    public function holidays(string $region, int $year): array
    {
        return array_values($this->send('GET', 'api/v1/holidays/'.rawurlencode($region), ['year' => $year]));
    }

    public function putHoliday(string $region, string $date, string $name): array
    {
        return $this->send('PUT', 'api/v1/holidays/'.rawurlencode($region).'/'.rawurlencode($date), ['name' => $name]);
    }

    public function removeHoliday(string $region, string $date): array
    {
        return $this->send('DELETE', 'api/v1/holidays/'.rawurlencode($region).'/'.rawurlencode($date));
    }

    private function bookingAction(string $bookingId, string $action, array $body): Booking
    {
        return Booking::fromArray($this->send('POST', 'api/v1/bookings/'.rawurlencode($bookingId).'/'.$action, array_filter($body, fn ($v) => $v !== null)));
    }

    /** @return array<string, mixed> the response's `data` */
    private function send(string $method, string $path, array $body = []): array
    {
        $options = $method === 'GET' ? ['query' => $body] : ($body === [] ? [] : ['json' => $body]);
        $options['headers'] = ['Authorization' => 'Bearer '.$this->trustKey, 'Accept' => 'application/json'];
        $options['http_errors'] = false;

        try {
            $response = $this->http->request($method, $path, $options);
        } catch (ConnectException $e) {
            throw new CalendarServiceUnavailable('calendar-service is unreachable: '.$e->getMessage(), 'unreachable', 0);
        } catch (GuzzleException $e) {
            throw new CalendarServiceUnavailable('calendar-service request failed: '.$e->getMessage(), 'transport', 0);
        }

        $json = json_decode((string) $response->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            return (array) ($json['data'] ?? []);
        }

        throw self::errorFor($response, $json);
    }

    /** ONE translation from the service's error envelope to the typed exceptions. */
    public static function errorFor(ResponseInterface $response, array $json): CalendarServiceException
    {
        $status = $response->getStatusCode();
        $errors = $json['errors'] ?? [];
        $code = is_array($errors) && is_string($errors['code'] ?? null) ? $errors['code'] : null;
        $message = (string) ($json['message'] ?? "calendar-service answered {$status}");
        $list = is_array($errors) && array_is_list($errors) ? $errors : [];

        return match (true) {
            $code === 'slot_unavailable' => new SlotUnavailable($message, $code, $status),
            $code === 'hold_expired' || $status === 410 => new HoldExpired($message, $code, $status),
            $status === 404 => new CalendarNotFound($message, $code, $status),
            $status >= 500 || $status === 429 => new CalendarServiceUnavailable($message, $code ?? 'server_error', $status),
            in_array($status, [409, 422], true) => new CalendarRequestRejected($message, $code, $status, $list),
            // 401: this product's trust key is missing, wrong or revoked. A deployment fault.
            default => new CalendarServiceException($message, $code ?? 'unexpected', $status, $list),
        };
    }
}
