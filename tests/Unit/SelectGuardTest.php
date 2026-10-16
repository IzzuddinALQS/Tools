<?php

namespace Tests\Unit;

use App\Services\Source\Exceptions\SelectGuardException;
use App\Services\Source\Sql\SelectGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Keamanan 1: tool tidak pernah menulis ke Oracle.
 *
 * Guard menolak DML, DDL, PL/SQL, `;` ganda, FOR UPDATE, dan db link.
 */
class SelectGuardTest extends TestCase
{
    private SelectGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new SelectGuard;
    }

    public function test_accepts_plain_select(): void
    {
        $this->assertTrue($this->guard->passes('SELECT * FROM APPS.PO_HEADERS'));
        $this->assertTrue($this->guard->passes(
            "SELECT PO_HEADER_ID, STATUS_CODE FROM APPS.PO_HEADERS WHERE STATUS_CODE = 'APPROVED'"
        ));
    }

    public function test_accepts_with_cte(): void
    {
        $this->assertTrue($this->guard->passes('WITH X AS (SELECT 1 AS A FROM DUAL) SELECT A FROM X'));
    }

    public function test_accepts_trailing_semicolon_only(): void
    {
        $this->assertTrue($this->guard->passes('SELECT 1 FROM DUAL;'));
    }

    #[DataProvider('rejectedStatements')]
    public function test_rejects(string $sql): void
    {
        $this->assertFalse(
            $this->guard->passes($sql),
            "SQL ini seharusnya ditolak: {$sql}"
        );
    }

    /**
     * @return array<string,array<int,string>>
     */
    public static function rejectedStatements(): array
    {
        return [
            'insert' => ['INSERT INTO T (A) VALUES (1)'],
            'update' => ['UPDATE T SET A = 1'],
            'delete' => ['DELETE FROM T'],
            'merge' => ['MERGE INTO T USING S ON (1=1) WHEN MATCHED THEN UPDATE SET A=1'],
            'drop' => ['DROP TABLE T'],
            'truncate' => ['TRUNCATE TABLE T'],
            'create' => ['CREATE TABLE T (A NUMBER)'],
            'alter' => ['ALTER TABLE T ADD (B NUMBER)'],
            'grant' => ['GRANT SELECT ON T TO PUBLIC'],
            'commit' => ['COMMIT'],
            'rollback' => ['ROLLBACK'],
            'call' => ['CALL SOME_PROC()'],
            'declare' => ['DECLARE X NUMBER; BEGIN NULL; END;'],
            'begin block' => ['BEGIN NULL; END;'],
            'execute immediate' => ["EXECUTE IMMEDIATE 'DROP TABLE T'"],
            'two statements' => ['SELECT 1 FROM DUAL; SELECT 2 FROM DUAL'],
            'select then drop' => ['SELECT 1 FROM DUAL; DROP TABLE T'],
            'for update' => ['SELECT * FROM T FOR UPDATE'],
            'db link' => ['SELECT * FROM T@REMOTE_DB'],
            'db link quoted' => ['SELECT * FROM "T"@"REMOTE_DB"'],
            'dbms pipe' => ["SELECT DBMS_PIPE.RECEIVE_MESSAGE('a') FROM DUAL"],
            'utl package' => ["SELECT UTL_HTTP.REQUEST('http://x') FROM DUAL"],
            'explain plan' => ['EXPLAIN PLAN FOR SELECT 1 FROM DUAL'],
            'empty' => ['   '],
            'cte delete' => ['WITH X AS (SELECT 1 FROM DUAL) DELETE FROM T'],
            'comment hidden drop' => ["SELECT 1 FROM DUAL /* x */ UNION SELECT 2 FROM DUAL WHERE 1=0; DROP TABLE T"],
        ];
    }

    public function test_semicolon_inside_string_literal_is_allowed(): void
    {
        $this->assertTrue(
            $this->guard->passes("SELECT * FROM T WHERE NOTE = 'DROP TABLE users; --'")
        );
    }

    public function test_for_update_inside_string_literal_is_allowed(): void
    {
        $this->assertTrue($this->guard->passes("SELECT 'FOR UPDATE' AS X FROM DUAL"));
    }

    public function test_delete_inside_string_literal_is_allowed(): void
    {
        $this->assertTrue($this->guard->passes(
            "SELECT REMARK FROM T WHERE REMARK = 'please delete this row'"
        ));
    }

    public function test_q_quoted_literal_with_semicolon_is_allowed(): void
    {
        $this->assertTrue($this->guard->passes("SELECT q'[a; b; c]' AS X FROM DUAL"));
    }

    public function test_line_comment_is_stripped_before_checking(): void
    {
        // Kata terlarang yang tersembunyi setelah baris komentar harus tetap
        // Tertangkap, dan isi komentar tidak boleh ikut dibaca sebagai kode.
        $this->assertTrue($this->guard->passes(
            "SELECT REMARK FROM T -- DELETE FROM T\nWHERE REMARK = 'x'"
        ));

        $this->assertFalse($this->guard->passes("SELECT 1 FROM DUAL\n; DROP TABLE T"));
    }

    public function test_assert_throws_with_reasons(): void
    {
        try {
            $this->guard->assert('DELETE FROM T');
            $this->fail('SelectGuardException seharusnya dilempar.');
        } catch (SelectGuardException $exception) {
            $this->assertSame('sql_not_allowed', $exception->errorCode());
            $this->assertNotEmpty($exception->reasons());
        }
    }

    public function test_normalize_strips_trailing_semicolon(): void
    {
        $this->assertSame('SELECT 1 FROM DUAL', $this->guard->normalize("SELECT 1 FROM DUAL;  \n"));
    }
}
