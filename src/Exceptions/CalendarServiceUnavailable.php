<?php

namespace Shirahcan\CalendarClient\Exceptions;

/**
 * The service could not be reached or failed (5xx). Booking must FAIL CLOSED (D13):
 * show "scheduling is briefly unavailable", never fall back to a local engine.
 */
class CalendarServiceUnavailable extends CalendarServiceException {}
