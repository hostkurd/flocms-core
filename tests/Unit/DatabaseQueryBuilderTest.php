<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\Database;
use FloCMS\Core\Model;
use FloCMS\Core\Pagination;
use FloCMS\Core\Tests\Support\MySqlConfig;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Backlog #3. Every test runs on SQLite and, when FLO_TEST_MYSQL_HOST is set,
 * on MySQL/MariaDB.
 */
final class DatabaseQueryBuilderTest extends TestCase
{
    private const LISTINGS = [
        // title, city, status, price, lat, lng
        ['Sea view flat', 'Erbil', 'active', 120000, 36.19, 44.01],
        ['Garden house', 'Duhok', 'active', 250000, 36.87, 42.99],
        ['City studio', 'Erbil', 'sold', 60000, 36.20, 44.02],
        ['Erbil penthouse', 'Sulaymaniyah', 'active', 480000, 35.56, 45.43],
        ['Family villa', 'Erbil', 'active', 390000, 36.21, 44.05],
        ['Old shop', 'Duhok', 'draft', 90000, 36.86, 42.98],
    ];

    public static function drivers(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql']];
    }

    private function db(string $driver): Database
    {
        if ($driver === 'mysql') {
            $c = MySqlConfig::require($this);
            $pdo = new PDO(
                "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
                $c['user'],
                $c['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $pdo->exec('DROP TABLE IF EXISTS listings');
            $pdo->exec('CREATE TABLE listings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(100) NOT NULL,
                city VARCHAR(50) NOT NULL,
                status VARCHAR(20) NOT NULL,
                price INT NOT NULL,
                lat DOUBLE NOT NULL,
                lng DOUBLE NOT NULL,
                FULLTEXT KEY listings_title (title)
            ) ENGINE=InnoDB');
        } else {
            $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE listings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                city TEXT NOT NULL,
                status TEXT NOT NULL,
                price INTEGER NOT NULL,
                lat REAL NOT NULL,
                lng REAL NOT NULL
            )');
        }

        $db = new Database($pdo);
        foreach (self::LISTINGS as [$title, $city, $status, $price, $lat, $lng]) {
            $db->table('listings')->insert(compact('title', 'city', 'status', 'price', 'lat', 'lng'));
        }

        return $db;
    }

    private static function titles(array $rows): array
    {
        $titles = array_map(fn (object $row) => $row->title, $rows);
        sort($titles);
        return $titles;
    }

    /* ---------- Grouped conditions ---------- */

    #[DataProvider('drivers')]
    public function testGroupedOrInsideAnd(string $driver): void
    {
        $db = $this->db($driver);

        $query = $db->table('listings')
            ->where('status', '=', 'active')
            ->where(fn (Database $q) => $q
                ->where('title', 'LIKE', '%Erbil%')
                ->orWhere('city', 'LIKE', '%Erbil%'));

        [$sql, $params] = $query->toSql();
        self::assertSame(
            'SELECT * FROM `listings` WHERE `status` = ? AND (`title` LIKE ? OR `city` LIKE ?)',
            $sql
        );
        self::assertSame(['active', '%Erbil%', '%Erbil%'], $params);

        // "City studio" is in Erbil but sold: the group must not leak the OR
        self::assertSame(['Erbil penthouse', 'Family villa', 'Sea view flat'], self::titles($query->get()));
    }

    #[DataProvider('drivers')]
    public function testOrWhereGroupAndNesting(string $driver): void
    {
        $db = $this->db($driver);

        $rows = $db->table('listings')
            ->where('status', '=', 'draft')
            ->orWhere(fn (Database $q) => $q
                ->where('city', '=', 'Erbil')
                ->where(fn (Database $q) => $q
                    ->where('price', '<', 100000)
                    ->orWhere('price', '>', 300000)))
            ->get();

        self::assertSame(['City studio', 'Family villa', 'Old shop'], self::titles($rows));
    }

    #[DataProvider('drivers')]
    public function testEmptyGroupIsIgnored(string $driver): void
    {
        $db = $this->db($driver);

        $query = $db->table('listings')->where('status', '=', 'sold')->where(fn (Database $q) => $q);

        self::assertSame('SELECT * FROM `listings` WHERE `status` = ?', $query->toSql()[0]);
        self::assertCount(1, $query->get());
    }

    #[DataProvider('drivers')]
    public function testGroupsWorkInUpdateDeleteAndCount(string $driver): void
    {
        $db = $this->db($driver);
        $erbilOrDuhok = fn (Database $q) => $q->where('city', '=', 'Erbil')->orWhere('city', '=', 'Duhok');

        self::assertSame(3, $db->table('listings')->where('status', '=', 'active')->where($erbilOrDuhok)->count());

        $db->table('listings')->where('status', '=', 'draft')->where($erbilOrDuhok)->update(['status' => 'archived']);
        self::assertSame(1, $db->table('listings')->where('status', '=', 'archived')->count());

        $db->table('listings')->where('status', '=', 'sold')->where($erbilOrDuhok)->delete();
        self::assertSame(5, $db->table('listings')->count());
    }

    #[DataProvider('drivers')]
    public function testHavingGroupAndRaw(string $driver): void
    {
        $db = $this->db($driver);

        $rows = $db->table('listings')
            ->select('city')
            ->selectRaw('COUNT(*) AS listings_count')
            ->groupBy('city')
            ->having(fn (Database $q) => $q
                ->having('listings_count', '>', 2)
                ->orHaving('city', '=', 'Sulaymaniyah'))
            ->orderBy('city')
            ->get();

        self::assertSame(['Erbil', 'Sulaymaniyah'], array_map(fn ($r) => $r->city, $rows));

        $rows = $db->table('listings')
            ->select('city')
            ->groupBy('city')
            ->havingRaw('MAX(price) >= ?', [250000])
            ->orderBy('city')
            ->get();

        self::assertSame(['Duhok', 'Erbil', 'Sulaymaniyah'], array_map(fn ($r) => $r->city, $rows));
    }

    /* ---------- BETWEEN ---------- */

    #[DataProvider('drivers')]
    public function testBetween(string $driver): void
    {
        $db = $this->db($driver);

        self::assertSame(
            ['Garden house', 'Sea view flat'],
            self::titles($db->table('listings')->whereBetween('price', [100000, 250000])->get())
        );
        self::assertSame(
            ['City studio', 'Erbil penthouse', 'Family villa', 'Old shop'],
            self::titles($db->table('listings')->whereNotBetween('price', [100000, 250000])->get())
        );
        self::assertSame(
            ['Erbil penthouse', 'Sea view flat'],
            self::titles($db->table('listings')
                ->where('city', '=', 'Sulaymaniyah')
                ->orWhereBetween('price', [110000, 130000])
                ->get())
        );
        self::assertSame(
            ['City studio', 'Erbil penthouse', 'Family villa', 'Garden house', 'Old shop', 'Sea view flat'],
            self::titles($db->table('listings')
                ->where('city', '=', 'Erbil')
                ->orWhereNotBetween('price', [0, 1])
                ->get())
        );

        [$sql, $params] = $db->table('listings')->whereNotBetween('price', [1, 2])->toSql();
        self::assertSame('SELECT * FROM `listings` WHERE `price` NOT BETWEEN ? AND ?', $sql);
        self::assertSame([1, 2], $params);
    }

    public function testBetweenNeedsTwoValues(): void
    {
        $db = new Database(new PDO('sqlite::memory:'));

        $this->expectException(InvalidArgumentException::class);
        $db->table('listings')->whereBetween('price', [1]);
    }

    /* ---------- Raw SQL ---------- */

    #[DataProvider('drivers')]
    public function testWhereRawWithBindings(string $driver): void
    {
        $db = $this->db($driver);

        $query = $db->table('listings')
            ->where('status', '=', 'active')
            ->whereRaw('price * 2 > ? OR city = ?', [700000, 'Duhok']);

        self::assertSame(
            'SELECT * FROM `listings` WHERE `status` = ? AND (price * 2 > ? OR city = ?)',
            $query->toSql()[0]
        );
        self::assertSame(['Erbil penthouse', 'Family villa', 'Garden house'], self::titles($query->get()));

        self::assertSame(
            ['City studio', 'Old shop'],
            self::titles($db->table('listings')
                ->where('price', '<', 50000)
                ->orWhereRaw('status <> ?', ['active'])
                ->get())
        );
    }

    public function testRawSqlRequiresMatchingBindings(): void
    {
        $db = new Database(new PDO('sqlite::memory:'));

        try {
            $db->table('listings')->whereRaw('price > ? AND city = ?', [1]);
            self::fail('Expected a binding count error.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('2 "?" placeholder(s) but 1 binding(s)', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $db->table('listings')->whereRaw('price > :min', ['min' => 1]);
    }

    public function testRawSqlWithoutPlaceholdersAcceptsEmptyBindings(): void
    {
        $db = new Database(new PDO('sqlite::memory:'));

        [$sql, $params] = $db->table('listings')->whereRaw('price > 0', [])->toSql();

        self::assertSame('SELECT * FROM `listings` WHERE (price > 0)', $sql);
        self::assertSame([], $params);
    }

    #[DataProvider('drivers')]
    public function testBindingOrderAcrossSelectWhereHavingAndOrder(string $driver): void
    {
        $db = $this->db($driver);

        $query = $db->table('listings')
            ->select('title')
            ->selectRaw('ABS(price - ?) AS diff', [200000])
            ->where('status', '=', 'active')
            ->orderByRaw('ABS(price - ?) ASC', [200000])
            ->limit(2);

        self::assertSame([200000, 'active', 200000], $query->toSql()[1]);

        $rows = $query->get();
        self::assertSame(['Garden house', 'Sea view flat'], array_map(fn ($r) => $r->title, $rows));
        self::assertEquals(50000, $rows[0]->diff);
    }

    public function testFullTextAndDistanceOnMySql(): void
    {
        $db = $this->db('mysql');

        $rows = $db->table('listings')
            ->whereRaw('MATCH(title) AGAINST(? IN BOOLEAN MODE)', ['+villa'])
            ->get();
        self::assertSame(['Family villa'], self::titles($rows));

        // Listings within 10 km of central Erbil, nearest first
        $rows = $db->table('listings')
            ->select('title')
            ->selectRaw('ST_Distance_Sphere(POINT(lng, lat), POINT(?, ?)) AS meters', [44.01, 36.19])
            ->whereRaw('ST_Distance_Sphere(POINT(lng, lat), POINT(?, ?)) <= ?', [44.01, 36.19, 10000])
            ->orderByRaw('meters ASC')
            ->get();

        self::assertSame(['Sea view flat', 'City studio', 'Family villa'], array_map(fn ($r) => $r->title, $rows));
    }

    /* ---------- Isolation and backwards compatibility ---------- */

    #[DataProvider('drivers')]
    public function testInterleavedQueriesDoNotShareState(string $driver): void
    {
        $db = $this->db($driver);

        $erbil = $db->table('listings')->where('city', '=', 'Erbil');
        // A second query built and run before the first one finishes
        $duhokCount = $db->table('listings')->where('city', '=', 'Duhok')->count();
        $erbil->where('status', '=', 'active');

        self::assertSame(2, $duhokCount);
        self::assertSame(['Family villa', 'Sea view flat'], self::titles($erbil->get()));
    }

    #[DataProvider('drivers')]
    public function testUnchainedCallsOnTheSharedInstanceStillWork(string $driver): void
    {
        $db = $this->db($driver);

        // 2.1 style, one statement per call
        $db->table('listings');
        $db->where('city', '=', 'Duhok');
        $db->orderBy('price', 'DESC');
        $rows = $db->get();
        self::assertSame(['Garden house', 'Old shop'], array_map(fn ($r) => $r->title, $rows));

        // Partly chained: conditions added on the result and on the shared instance
        $db->table('listings')->where('city', '=', 'Erbil');
        $db->where('status', '=', 'active');
        self::assertSame(2, $db->count());

        $db->table('listings')->where('title', '=', 'Old shop');
        $db->update(['status' => 'archived']);
        self::assertSame('archived', $db->table('listings')->where('title', '=', 'Old shop')->first()->status);

        // Executing resets the query, as before
        $db->table('listings')->where('city', '=', 'Erbil');
        $db->get();
        $this->expectException(RuntimeException::class);
        $db->delete();
    }

    #[DataProvider('drivers')]
    public function testUpdateAndDeleteStillRequireWhere(string $driver): void
    {
        $db = $this->db($driver);

        $this->expectException(RuntimeException::class);
        $db->table('listings')->where(fn (Database $q) => $q)->delete();
    }

    public function testMySqlStillBindsIntegersAsStrings(): void
    {
        $db = $this->db('mysql');
        $db->pdo()->exec("UPDATE listings SET city = 'abc' WHERE title = 'Old shop'");

        // As a numeric comparison, 'abc' = 0 would be true and match the row
        self::assertSame(0, $db->table('listings')->where('city', '=', 0)->count());
    }

    public function testWhereNeedsOperatorAndValue(): void
    {
        $db = new Database(new PDO('sqlite::memory:'));

        $this->expectException(InvalidArgumentException::class);
        $db->table('listings')->where('price', '=');
    }

    #[DataProvider('drivers')]
    public function testNestedTransactionsAcrossBuilders(string $driver): void
    {
        $db = $this->db($driver);

        $db->transaction(function (Database $db) {
            $db->table('listings')->insert([
                'title' => 'Kept', 'city' => 'Erbil', 'status' => 'active', 'price' => 1, 'lat' => 0, 'lng' => 0,
            ]);

            try {
                $db->table('listings')->transaction(function (Database $inner) {
                    $inner->table('listings')->insert([
                        'title' => 'Rolled back', 'city' => 'Erbil', 'status' => 'active', 'price' => 1, 'lat' => 0, 'lng' => 0,
                    ]);
                    throw new RuntimeException('fail inner');
                });
            } catch (RuntimeException) {
            }
        });

        self::assertSame(1, $db->table('listings')->where('title', '=', 'Kept')->count());
        self::assertSame(0, $db->table('listings')->where('title', '=', 'Rolled back')->count());
        self::assertFalse($db->pdo()->inTransaction());
    }

    /* ---------- Pagination ---------- */

    #[DataProvider('drivers')]
    public function testPaginate(string $driver): void
    {
        $db = $this->db($driver);

        $page = $db->table('listings')
            ->where('status', '=', 'active')
            ->orderBy('price')
            ->paginate(2, 3);

        self::assertSame(['Erbil penthouse'], array_map(fn ($r) => $r->title, $page['data']));
        self::assertSame(4, $page['pagination']['items_count']);
        self::assertSame(2, $page['pagination']['total_pages']);
        self::assertSame(2, $page['pagination']['cur_page']);
        self::assertFalse($page['pagination']['has_next']);
        self::assertTrue($page['pagination']['has_prev']);
        self::assertSame(1, $page['pagination']['prev_page']);
    }

    #[DataProvider('drivers')]
    public function testPaginateCountsGroupsNotRows(string $driver): void
    {
        $db = $this->db($driver);

        $page = $db->table('listings')
            ->select('city')
            ->selectRaw('COUNT(*) AS n')
            ->groupBy('city')
            ->orderBy('city')
            ->paginate(1, 2);

        self::assertSame(['Duhok', 'Erbil'], array_map(fn ($r) => $r->city, $page['data']));
        self::assertSame(3, $page['pagination']['items_count']);
        self::assertTrue($page['pagination']['has_next']);
    }

    public function testPaginationMetaMatchesPagingArray(): void
    {
        $model = new class extends Model {
            public function __construct()
            {
            }
        };

        foreach ([[1, 10, 0], [1, 10, 5], [2, 10, 25], [3, 10, 25], [9, 10, 100], ['0', '0', '-5']] as [$page, $per, $total]) {
            self::assertSame(Pagination::meta($page, $per, $total), $model->pagingArray($page, $per, $total));
        }

        self::assertSame([
            'items_count' => 100, 'total_pages' => 10, 'cur_page' => 9, 'per_page' => 10,
            'next_page' => 10, 'prev_page' => 8, 'has_next' => true, 'has_prev' => true,
            'last_page' => 10, 'last_pages' => [7, 8, 9],
        ], Pagination::meta(9, 10, 100));
    }
}
