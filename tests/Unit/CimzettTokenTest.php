<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\CimzettToken;
use PHPUnit\Framework\TestCase;

final class CimzettTokenTest extends TestCase
{
    private const DOMAIN = 'bekuldes.szamlafolyo.hu';

    private const TOKEN = 'a7f3c1d9b2e40518';

    public function test_egyszeru_cimzett(): void
    {
        $token = CimzettToken::kereses(
            ['to' => 'SzámlaFolyó <'.self::TOKEN.'@'.self::DOMAIN.'>'],
            'catchall',
            self::DOMAIN,
        );

        $this->assertSame(self::TOKEN, $token);
    }

    /** Több címzett között is meg kell találnia a miénket. */
    public function test_tobb_cimzett_kozul(): void
    {
        $token = CimzettToken::kereses(
            ['to' => 'konyveles@partner.hu, '.self::TOKEN.'@'.self::DOMAIN.', fonok@partner.hu'],
            'catchall',
            self::DOMAIN,
        );

        $this->assertSame(self::TOKEN, $token);
    }

    /**
     * Titkos másolatnál a mi címünk csak a borítékon van — a To fejlécben
     * nem szerepel. Enélkül a Bcc-vel beküldött bizonylat elveszne.
     */
    public function test_bcc_eseten_a_borítékbol(): void
    {
        $token = CimzettToken::kereses(
            [
                'to' => 'valaki@mashol.hu',
                'delivered-to' => self::TOKEN.'@'.self::DOMAIN,
            ],
            'catchall',
            self::DOMAIN,
        );

        $this->assertSame(self::TOKEN, $token);
    }

    public function test_plusz_cimzes_a_token_utan(): void
    {
        $token = CimzettToken::kereses(
            ['to' => self::TOKEN.'+marciusi@'.self::DOMAIN],
            'catchall',
            self::DOMAIN,
        );

        $this->assertSame(self::TOKEN, $token);
    }

    public function test_plusz_mod(): void
    {
        $token = CimzettToken::kereses(
            ['to' => 'bekuldes+'.self::TOKEN.'@szamlafolyo.hu'],
            'plus',
            'szamlafolyo.hu',
            'bekuldes@szamlafolyo.hu',
        );

        $this->assertSame(self::TOKEN, $token);
    }

    /** Idegen domain vagy rossz alakú token: nincs találat, nem tippelünk. */
    public function test_nem_talal_ki_semmit(): void
    {
        $this->assertNull(CimzettToken::kereses(
            ['to' => self::TOKEN.'@masik-domain.hu'],
            'catchall',
            self::DOMAIN,
        ));

        $this->assertNull(CimzettToken::kereses(
            ['to' => 'info@'.self::DOMAIN],
            'catchall',
            self::DOMAIN,
        ));

        $this->assertNull(CimzettToken::kereses([], 'catchall', self::DOMAIN));
    }

    /** A feladó soha nem dönt: attól, hogy tőle jött, még nem az ő cége. */
    public function test_a_feladot_figyelmen_kivul_hagyja(): void
    {
        $this->assertNull(CimzettToken::kereses(
            ['from' => self::TOKEN.'@'.self::DOMAIN, 'to' => 'valaki@mashol.hu'],
            'catchall',
            self::DOMAIN,
        ));
    }
}
