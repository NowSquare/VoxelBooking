<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Locale;
use PHPUnit\Framework\TestCase;

/**
 * Initialising the locale engine points it at a translation directory, so
 * translations cached from anywhere else, including the empty result of a
 * lookup made before initialisation, must not survive it.
 */
final class LocaleInitTest extends TestCase
{
    public function testInitDiscardsTranslationsCachedBeforeIt(): void
    {
        Locale::init(sys_get_temp_dir() . '/vb-no-translations-' . bin2hex(random_bytes(4)));
        $this->assertSame('booking.api.slot_unavailable', Locale::translate('booking.api.slot_unavailable'));

        Locale::init(dirname(__DIR__, 3));

        $expected = (require dirname(__DIR__, 3) . '/lang/en/booking.php')['api']['slot_unavailable'];
        $this->assertSame($expected, Locale::translate('booking.api.slot_unavailable'));
    }
}
