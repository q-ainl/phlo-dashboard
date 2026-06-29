<?php
use PHPUnit\Framework\TestCase;

// Boot/route/login/db/API smoke for the dashboard. Its CI otherwise only compiles the app; this builds
// it the same way (engine + CMS under vendor/phlo, config from the committed examples, build mode on),
// then serves it with PHP's built-in server on a dependency-free SQLite database (PHLO_TEST_DB=sqlite,
// the same switch the engine's db fixture uses) and proves the app actually runs: the full module stack
// boots and renders the login page, a public API route dispatches and answers with structured JSON, and
// the model layer reaches the (SQLite) database. Engine/CMS paths: PHLO_ENGINE_PATH / PHLO_CMS_PATH.
final class DashboardSmokeTest extends TestCase {

	private static string $root  = '';
	private static string $entry = '';
	private static $server = null;
	private static int $port = 0;

	private static function cli(string ...$args):array {
		$proc = proc_open([PHP_BINARY, self::$entry, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out = (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	private static function http(string $path):array {
		$ctx  = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
		$body = (string)file_get_contents('http://127.0.0.1:'.self::$port.$path, false, $ctx);
		$status = 0;
		foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
		return [$status, $body];
	}

	public static function setUpBeforeClass():void {
		self::$root  = dirname(__DIR__).'/';
		self::$entry = self::$root.'www/app.php';

		// Mirror CI: the engine and CMS live under vendor/phlo/*. CI checks them out there; locally we
		// symlink them from PHLO_ENGINE_PATH / PHLO_CMS_PATH so the test also runs from a bare checkout.
		@mkdir(self::$root.'vendor/phlo', 0777, true);
		$engine = rtrim(getenv('PHLO_ENGINE_PATH') ?: '/srv/control/phlo', '/');
		$cms    = rtrim(getenv('PHLO_CMS_PATH') ?: '/srv/control/CMS', '/');
		if (!file_exists(self::$root.'vendor/phlo/tech') && is_file($engine.'/phlo.php')) @symlink($engine, self::$root.'vendor/phlo/tech');
		if (!file_exists(self::$root.'vendor/phlo/cms') && is_dir($cms)) @symlink($cms, self::$root.'vendor/phlo/cms');
		if (!is_file(self::$root.'vendor/phlo/tech/phlo.php')) self::markTestSkipped('dashboard smoke needs the Phlo engine - set PHLO_ENGINE_PATH or check it out under vendor/phlo/tech');

		// The SQLite switch must reach the build, the served app and the CLI checks.
		putenv('PHLO_TEST_DB=sqlite');

		// Prepare config from the committed examples (build mode on, a local host), exactly like CI.
		file_put_contents(self::$entry, str_replace(['build: false,', 'dashboard.example.tld'], ['build: true,', 'localhost'], file_get_contents(self::$root.'www/app.php.example')));
		copy(self::$root.'data/app.example.json', self::$root.'data/app.json');
		@unlink(self::$root.'data/test.db');

		[$code, $out, $err] = self::cli('build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");

		self::$port   = 8920 + (getmypid() % 1000);
		self::$server = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:'.self::$port, self::$entry],
			[1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes,
			self::$root.'www'
		);
		self::assertIsResource(self::$server, 'php -S did not start');
		$up = false;
		for ($i = 0; $i < 50 && !$up; ++$i){
			usleep(100_000);
			$sock = @fsockopen('127.0.0.1', self::$port, $e, $s, 0.2);
			if ($sock){ fclose($sock); $up = true; }
		}
		self::assertTrue($up, 'php -S did not come up on port '.self::$port);
	}

	public static function tearDownAfterClass():void {
		if (self::$server){ proc_terminate(self::$server); proc_close(self::$server); }
	}

	public function testBootsAndRendersLoginPage():void {
		[$status, $body] = self::http('/');
		$this->assertSame(200, $status, 'the dashboard home responds');
		$this->assertStringContainsString('<title>Login - Phlo Dashboard</title>', $body, 'an unauthenticated visit boots the full app (auth + CMS + every module) and renders the login page');
	}

	public function testPublicApiRouteReturnsStructuredJson():void {
		[$status, $body] = self::http('/api/feed');
		$this->assertSame(401, $status, 'the public fleet feed route dispatches and rejects an unauthenticated caller');
		$this->assertSame('unauthorized', (json_decode($body, true)['error'] ?? null), 'it answers with a structured JSON error, not an HTML page or a crash');
	}

	public function testModelLayerReachesSqliteDatabase():void {
		$src = <<<'PHLO'
		user::DB()->query("DROP TABLE IF EXISTS smoke")
		user::DB()->query("CREATE TABLE smoke (id INTEGER PRIMARY KEY, n TEXT)")
		user::DB()->create("smoke", n: "ok")
		return user::DB()->query("SELECT n FROM smoke WHERE id = 1")->fetchColumn()
		PHLO;
		[$code, $out, $err] = self::cli('phlo_eval', $src);
		$this->assertSame(0, $code, "phlo_eval failed:\n$out$err");
		$this->assertSame('ok', json_decode(trim($out), true), 'a model DDL + insert + read round-trips on the env-switched SQLite database');
	}
}
