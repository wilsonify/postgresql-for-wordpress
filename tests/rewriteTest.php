<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . "/../");
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

require_once __DIR__ . "/../pg4wp/db.php";

final class RewriteTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new class() {
            public $categories = "wp_categories";
            public $comments = "wp_comments";
            public $prefix = "wp_";
            public $options = "wp_options";
            public $posts = "wp_posts";
            public $terms = "wp_terms";
            public $term_relationships = "wp_term_relationships";
        };
    }

    public function test_it_can_rewrite_users_admin_query()
    {
        $sql = 'SELECT COUNT(NULLIF(`meta_value` LIKE \'%"administrator"%\', false)), COUNT(NULLIF(`meta_value` = \'a:0:{}\', false)), COUNT(*) FROM wp_usermeta INNER JOIN wp_users ON user_id = ID WHERE meta_key = \'wp_capabilities\'';
        $expected = 'SELECT COUNT(NULLIF(meta_value ILIKE \'%"administrator"%\', false)) AS count0, COUNT(NULLIF(meta_value = \'a:0:{}\', false)) AS count1, COUNT(*) FROM wp_usermeta INNER JOIN wp_users ON user_id = "ID" WHERE meta_key = \'wp_capabilities\'';
        $actual = pg4wp_rewrite($sql);
        $this->assertSame($expected, $actual);
    }

    public function test_it_adds_group_by()
    {
        $sql = 'SELECT COUNT(id), username FROM users';
        $expected = 'SELECT COUNT(id) AS count0, username FROM users GROUP BY username';
        $actual = pg4wp_rewrite($sql);
        $this->assertSame($expected, $actual);
    }

    public function test_it_preserves_where_with_group_by()
    {
        $sql = "SELECT post_status, COUNT(*) AS num_posts FROM wp_posts WHERE post_type = 'page' GROUP BY post_status";
        $expected = "SELECT post_status, COUNT(*) AS num_posts FROM wp_posts WHERE post_type = 'page' GROUP BY post_status";
        $actual = pg4wp_rewrite($sql);
        $this->assertSame($expected, $actual);
    }

    public function test_it_converts_insert_set_to_values()
    {
        $sql = "INSERT INTO wp_aioseo_cache SET `key` = 'val', `value` = 'b:1;'";
        $actual = pg4wp_rewrite($sql);
        $this->assertStringContainsString('(key, value)', $actual);
        $this->assertStringContainsString("VALUES ('val', 'b:1;')", $actual);
    }

    public function test_it_converts_get_lock()
    {
        $sql = "SELECT GET_LOCK('test_lock', 0)";
        $actual = pg4wp_rewrite($sql);
        $this->assertStringContainsString('pg_try_advisory_lock', $actual);
        $this->assertStringContainsString('hashtext', $actual);
    }

    public function test_it_converts_release_lock()
    {
        $sql = "SELECT RELEASE_LOCK('test_lock')";
        $actual = pg4wp_rewrite($sql);
        $this->assertStringContainsString('pg_advisory_unlock', $actual);
        $this->assertStringContainsString('hashtext', $actual);
    }

    public function test_it_strips_from_dual()
    {
        $sql = "SELECT NULL FROM DUAL WHERE (SELECT NULL FROM DUAL) IS NULL";
        $actual = pg4wp_rewrite($sql);
        $this->assertStringNotContainsString('DUAL', $actual);
    }

    public function test_it_converts_limit_comma_syntax()
    {
        $sql = 'SELECT ID, post_title FROM wp_posts WHERE post_type = \'post\' LIMIT 0, 10';
        $actual = pg4wp_rewrite($sql);
        $this->assertStringContainsString('LIMIT 10 OFFSET 0', $actual);
    }

    public function test_it_converts_date_functions()
    {
        $sql = "SELECT YEAR(post_date) AS year, MONTH(post_date) AS month, COUNT(*) AS posts FROM wp_posts GROUP BY YEAR(post_date), MONTH(post_date)";
        $actual = pg4wp_rewrite($sql);
        $this->assertStringContainsString('EXTRACT(YEAR FROM', $actual);
        $this->assertStringContainsString('EXTRACT(MONTH FROM', $actual);
        $this->assertStringContainsString('GROUP BY', $actual);
    }

    public function test_it_handles_insert_ignore()
    {
        $sql = "INSERT IGNORE INTO wp_options (option_name, option_value, autoload) VALUES ('test_key', 'test_value', 'yes')";
        $actual = pg4wp_rewrite($sql);
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $actual);
    }

    public function test_it_returns_insert_with_returning()
    {
        $sql = "INSERT INTO wp_posts (post_title, post_content) VALUES ('Test', 'Content')";
        $actual = pg4wp_rewrite($sql);
        $this->assertStringContainsString('RETURNING *', $actual);
    }
}
