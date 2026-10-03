<?php

namespace Shirahcan\CalendarClient\Laravel\Http;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\CalendarClient\Laravel\Events\CalendarServiceEventReceived;
use Shirahcan\CalendarClient\Laravel\Models\CalendarServiceEvent;
use Shirahcan\CalendarClient\WebhookSignature;

/**
 * The receiver for calendar-service's signed events, the same in every product (K4). Register it
 * once (CalendarKit::webhookRoute()) and listen for CalendarServiceEventReceived.
 *
 * ⚠ Reachable from the internet, so the signature is the whole defence: no secret configured =
 * refuse everything. `event_id` is unique, so a redelivery is acknowledged and recorded once.
 *
 * Status codes are a contract with the service: 2xx = done; 401 = refused for good; 422 = a
 * malformed body; 5xx = the service retries with backoff.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('calendar-client.callback_secret');
        $raw = $request->getContent();

        if ($secret === '' || ! WebhookSignature::verify($raw, $request->header('X-Calendar-Signature'), $request->header('X-Calendar-Timestamp'), $secret)) {
            report(new \RuntimeException('calendar-service callback refused: '.($secret === '' ? 'CALENDAR_SERVICE_CALLBACK_SECRET is not set' : 'signature mismatch')));

            return response()->json(['success' => false, 'message' => 'Invalid signature'], 401);
        }

        $body = json_decode($raw, true);
        $eventId = is_array($body) ? (string) ($body['event_id'] ?? '') : '';
        $type = is_array($body) ? (string) ($body['event'] ?? '') : '';
        if ($eventId === '' || $type === '') {
            return response()->json(['success' => false, 'message' => 'event_id and event are required'], 422);
        }

        try {
            $row = CalendarServiceEvent::firstOrCreate(
                ['event_id' => mb_substr($eventId, 0, 64)],
                ['event_type' => mb_substr($type, 0, 64), 'payload' => $body]
            );
        } catch (UniqueConstraintViolationException) {
            // Two deliveries of the same event raced; the other one recorded (and announced) it.
            return response()->json(['success' => true, 'message' => 'Received']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'Internal server error'], 500);
        }

        if ($row->wasRecentlyCreated) {
            try {
                CalendarServiceEventReceived::dispatch($row);
            } catch (\Throwable $e) {
                // Recorded; a listener's fault must not make the service redeliver an event we hold.
                report($e);
            }
        }

        return response()->json(['success' => true, 'message' => 'Received']);
    }
}
