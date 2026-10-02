<?php

namespace Shirahcan\CalendarClient\Exceptions;

/** The time is no longer offered (someone else took it, or it was never bookable). Ask the person to pick again. */
class SlotUnavailable extends CalendarServiceException {}
