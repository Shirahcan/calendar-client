<?php

namespace Shirahcan\CalendarClient\Exceptions;

/** The service refused on the merits (422 validation, 409 invalid state). Fixing the request helps; retrying does not. */
class CalendarRequestRejected extends CalendarServiceException {}
