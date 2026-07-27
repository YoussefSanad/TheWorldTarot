<?php

namespace App\Enums;

/**
 * Which product a row is, and the segment its page lives at.
 *
 * The value is the URL: /en/readings/one-card and /es/lecturas/one-card are the
 * same product. Identifiers are never translated and never editable, so a link
 * cannot be broken by renaming a reading, and no redirect layer is needed. The
 * SEO cost of an English word in a Spanish URL was accepted knowingly on
 * 26 July 2026. See .claude/translation.md section 9.
 *
 * This is the whole catalogue, fixed for the life of the site. The rows arrive
 * by milestone: the four readings now, the viewing room pass in M4, the rush
 * add-on in M5. A case without a row simply 404s, which is why the catalogue
 * can be declared up front while the rows are not.
 */
enum ProductKey: string
{
    case OneCard = 'one-card';
    case ThreeCard = 'three-card';
    case MonthAhead = 'month-ahead';
    case InDepth = 'in-depth';

    // Not seeded yet. See the milestones above.
    case ViewingRoomPass = 'viewing-room-pass';
    case Rush24h = 'rush-24h';

    /**
     * The four readings, which are the only products that exist today and the
     * only ones the seeder creates.
     *
     * @return array<int, self>
     */
    public static function readings(): array
    {
        return [self::OneCard, self::ThreeCard, self::MonthAhead, self::InDepth];
    }

    /**
     * The English name a product starts life with. Copy after that belongs to
     * the client, so this is only ever used when seeding a row for the first
     * time.
     */
    public function defaultName(): string
    {
        return match ($this) {
            self::OneCard => 'One Card Reading',
            self::ThreeCard => 'Three Card Reading',
            self::MonthAhead => 'Month Ahead Reading',
            self::InDepth => 'In Depth Reading',
            self::ViewingRoomPass => 'Viewing Room Pass',
            self::Rush24h => '24 Hour Rush',
        };
    }

    public function defaultSortOrder(): int
    {
        return match ($this) {
            self::OneCard => 1,
            self::ThreeCard => 2,
            self::MonthAhead => 3,
            self::InDepth => 4,
            self::ViewingRoomPass => 5,
            self::Rush24h => 6,
        };
    }
}
