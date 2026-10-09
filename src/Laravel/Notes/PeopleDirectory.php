<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

/** Names for the authors of notes (the service holds auth ids only). Optional, per product. */
interface PeopleDirectory
{
    /**
     * @param list<string> $authIds
     * @return array<string, string> auth id => display name
     */
    public function names(array $authIds): array;
}
