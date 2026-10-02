<?php

namespace Shirahcan\CalendarClient\Exceptions;

/** The hold lapsed before it was confirmed (e.g. payment took too long). Start again from slot selection. */
class HoldExpired extends CalendarServiceException {}
