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
	private static string $app   = '';
	private static string $entry = '';
	private static $server = null;
	private static int $port = 0;

	private static function rmdir(string $dir):void {
		if (!is_dir($dir)) return;
		foreach (scandir($dir) as $f){
			if ($f === '.' || $f === '..') continue;
			$p = $dir.'/'.$f;
			is_link($p) || !is_dir($p) ? @unlink($p) : self::rmdir($p);
		}
		@rmdir($dir);
	}

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

	private static function post(string $path, array $data):array {
		$ctx = stream_context_create(['http' => [
			'method'        => 'POST',
			'header'        => "Content-Type: application/x-www-form-urlencoded\r\nX-Requested-With: phlo",
			'content'       => http_build_query($data),
			'timeout'       => 5,
			'ignore_errors' => true,
		]]);
		$body = (string)file_get_contents('http://127.0.0.1:'.self::$port.$path, false, $ctx);
		$status = 0;
		foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
		return [$status, $body];
	}

	public static function setUpBeforeClass():void {
		self::$root = dirname(__DIR__).'/';

		$engine = rtrim(getenv('PHLO_ENGINE_PATH') ?: '/srv/control/phlo', '/');
		$cms    = rtrim(getenv('PHLO_CMS_PATH') ?: '/srv/control/CMS', '/');
		if (!is_file($engine.'/phlo.php')) self::markTestSkipped('dashboard smoke needs the Phlo engine - set PHLO_ENGINE_PATH or check it out at /srv/control/phlo');

		// Build and serve from a throwaway app root under the system temp dir, NEVER the active checkout, so
		// the test cannot clobber a node's own www/app.php or data/app.json. The source (modules, engine, CMS)
		// is symlinked in; the entry and config are written from the committed examples, exactly like CI.
		self::$app   = rtrim(sys_get_temp_dir(), '/').'/dash-smoke-'.getmypid().'/';
		self::$entry = self::$app.'www/app.php';
		self::rmdir(self::$app);
		@mkdir(self::$app.'www', 0777, true);
		@mkdir(self::$app.'data', 0777, true);
		@mkdir(self::$app.'vendor/phlo', 0777, true);
		@symlink(self::$root.'app.phlo', self::$app.'app.phlo');
		@symlink(self::$root.'modules', self::$app.'modules');
		@symlink($engine, self::$app.'vendor/phlo/tech');
		@symlink($cms, self::$app.'vendor/phlo/cms');

		// The SQLite switch must reach the build, the served app and the CLI checks.
		putenv('PHLO_TEST_DB=sqlite');

		file_put_contents(self::$entry, str_replace(['build: false,', 'dashboard.example.tld'], ['build: true,', 'localhost'], file_get_contents(self::$root.'www/app.php.example')));
		copy(self::$root.'data/app.example.json', self::$app.'data/app.json');

		[$code, $out, $err] = self::cli('build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");

		self::$port   = 8920 + (getmypid() % 1000);
		self::$server = proc_open(
			[PHP_BINARY, '-d', 'apc.enabled=1', '-d', 'apc.enable_cli=1', '-S', '127.0.0.1:'.self::$port, self::$entry],
			[1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes,
			self::$app.'www'
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
		self::$app && self::rmdir(self::$app);
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

	public function testUnroutedPathAnswers404():void {
		// A public prefix without a matching route, so the request reaches the controller fallback
		// instead of the login gate. It has to say 404: a page that answers 200 with "not found"
		// is indistinguishable from a working one for a monitor, a crawler or a client script.
		[$status, $body] = self::http('/api/wa/no-such-endpoint');
		$this->assertSame(404, $status, 'a path no route answered reports not found');
		$this->assertStringContainsString('Page not found!', $body, 'and still renders the page rather than a bare status');
	}

	public function testLoginIsRateLimited():void {
		// Skip only when apcu is genuinely absent; the throttle uses it and this SQLite smoke test has no MySQL.
		if (!extension_loaded('apcu')) $this->markTestSkipped('login throttle test needs the apcu extension');
		$throttled = false;
		for ($i = 1; $i <= 12; $i++){
			[, $body] = self::post('/login', ['email' => 'nobody@test.invalid', 'password' => 'wrong']);
			if (!str_contains($body, 'Too many attempts')) continue;
			$throttled = $i > 10;
			break;
		}
		$this->assertTrue($throttled, 'the eleventh login attempt from one IP is throttled, earlier ones are not');
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

	public function testBackupCardRendersSummaryAndFileLog():void {
		// en() is the guarded passthrough app.phlo defines on a web request; a bare CLI eval does not run that,
		// so declare the same shim here to exercise the render with the default (untranslated) labels.
		$src = <<<'PHLO'
		if (!function_exists('en')){function en($t, ...$a){return $a ? sprintf($t, ...$a) : $t;}}
		$b = ['ts' => '2026-07-19 14:01:09', 'copied' => 3, 'removed' => 1, 'files' => ['a/one.txt', 'b/two.sql.gz'], 'gone' => ['c/old.log']]
		return ['single' => backup::card('Q-dev', $b, true), 'multi' => backup::card('Q-dev', $b, false)]
		PHLO;
		[$code, $out, $err] = self::cli('phlo_eval', $src);
		$this->assertSame(0, $code, "phlo_eval failed:\n$out$err");
		$r = json_decode(trim($out), true);
		$this->assertIsArray($r, "backup::card output decoded:\n$out");
		[$single, $multi] = [$r['single'], $r['multi']];
		// The summary reads as a plain sentence with the run time and the counts.
		$this->assertStringContainsString('Q-dev: ran 2026-07-19 14:01 - 3 files copied, 1 deleted', $single, 'the summary line names the node, run time and file counts');
		// It is a collapsible entry whose body is a plain file log, not a table.
		$this->assertStringContainsString('<details', $single);
		$this->assertStringContainsString('<pre', $single);
		$this->assertStringNotContainsString('<table', $single, 'the file dump renders as a log, not a table');
		$this->assertStringContainsString('a/one.txt', $single, 'copied files are listed');
		$this->assertStringContainsString('[deleted] c/old.log', $single, 'deleted files are listed and marked');
		// A single node opens by default; with more than one node they stay collapsed.
		$this->assertMatchesRegularExpression('/<details[^>]*\bopen\b/', $single, 'a single backup entry is expanded by default');
		$this->assertDoesNotMatchRegularExpression('/<details[^>]*\bopen\b/', $multi, 'one of several entries stays collapsed');
	}

	public function testProductCardReadsPublicMetadataAndRendersBothStates():void {
		// Parsing and rendering are pure functions over fetched strings, so the whole card is exercised here
		// without touching the network. en() is the shim app.phlo installs on a web request.
		$src = <<<'PHLO'
		if (!function_exists('en')){function en($t, ...$a){return $a ? sprintf($t, ...$a) : $t;}}
		$html = '<html><head><title>Fallback</title><meta property="og:title" content="Phlo &amp; Co"><meta property="og:description" content="One language."><meta property="og:image" content="/icon.webp"><meta property="og:site_name" content="Phlo"></head></html>'
		$xml = '<urlset><url><loc>https://phlo.tech/</loc><xhtml:link rel="alternate" hreflang="x-default" href="https://phlo.tech/"/><xhtml:link rel="alternate" hreflang="nl" href="https://phlo.tech/nl"/></url><url><loc>https://phlo.tech/docs/views</loc></url></urlset>'
		$face = ['host' => 'phlo.tech', 'app' => 'phlo.tech', 'env' => 'prod', 'code' => 200, 'ms' => 42, 'node' => 'qai', 'nodeLabel' => 'Q-AI', 'visitors' => 1652, 'errors' => 0, 'seo' => true, 'indexable' => true]
		$layers = [['host' => 'stage.phlo.tech', 'env' => 'prod', 'code' => 200, 'seo' => true, 'indexable' => false], ['host' => 'dev.phlo.tech', 'env' => 'dev', 'code' => 401]]
		$product = ['app' => 'phlo.tech', 'face' => $face, 'layers' => $layers]
		$pages = product::pages($xml)
		$meta = ['host' => 'phlo.tech', 'fetched' => time() - 7200, 'home' => product::meta($html, 'https://phlo.tech'), 'pages' => $pages]
		return ['home' => $meta['home'], 'pages' => $pages, 'groups' => array_keys(product::grouped($pages)), 'langs' => product::langs($pages), 'card' => product::card($product, $meta), 'blank' => product::card($product, null)]
		PHLO;
		[$code, $out, $err] = self::cli('phlo_eval', $src);
		$this->assertSame(0, $code, "phlo_eval failed:\n$out$err");
		$r = json_decode(trim($out), true);
		$this->assertIsArray($r, "product render output decoded:\n$out");
		// og:* wins over the plain title, entities are decoded and a relative image resolves against the site.
		$this->assertSame('Phlo & Co', $r['home']['title']);
		$this->assertSame('https://phlo.tech/icon.webp', $r['home']['image'], 'a relative og:image resolves against the site, never against the dashboard');
		// The sitemap carries the page list and the language alternates the picker needs.
		$this->assertSame(['/', '/docs/views'], array_column($r['pages'], 'path'));
		$this->assertSame(['nl' => '/nl'], $r['pages'][0]['alt'], 'hreflang alternates are kept as paths, x-default is dropped');
		$this->assertSame(['/', 'docs'], $r['groups'], 'pages group by first path segment with the root level first');
		$this->assertSame(['nl'], $r['langs']);
		// A loaded card shows the preview, both pickers and the sitemap count.
		$this->assertStringContainsString('<img src="/product/image/phlo.tech/0/-1"', $r['card'], 'the image is served from our own origin, addressed by environment and page, never by a URL from the request');
		$this->assertStringContainsString('loading="lazy"', $r['card']);
		$this->assertStringContainsString('Phlo &amp; Co', $r['card'], 'remote metadata is escaped on output');
		$this->assertStringContainsString('<option value="1">/docs/views</option>', $r['card']);
		$this->assertStringContainsString('class="pd-lang"', $r['card']);
		$this->assertStringContainsString('2 pages', $r['card']);
		$this->assertStringContainsString('2 hours old', $r['card'], 'the card says how old its preview is instead of refreshing itself');
		// The preview is a link to the page it shows, opened in a new tab.
		$this->assertStringContainsString('<a href="https://phlo.tech/" target="_blank" class="pd-link">', $r['card'], 'the whole preview links to the page it shows');
		// Other hosts of the same app are selectable pills on this card, named after what sets them apart.
		$this->assertStringContainsString('data-env="0" title="phlo.tech" class="pd-env on"', $r['card'], 'the published host is the selected environment');
		$this->assertStringContainsString('data-env="1" title="stage.phlo.tech" class="pd-env"', $r['card']);
		$this->assertStringContainsString('<span>stage</span>', $r['card'], 'a layer is labelled by what distinguishes it from the face');
		$this->assertStringContainsString('<span>dev</span>', $r['card']);
		$this->assertStringNotContainsString('<article', substr($r['card'], 1), 'every environment stays inside the one product card');
		// Without a stored preview the card is an explicit empty state: no picker, no fetch, just a button.
		$this->assertStringContainsString('data-empty', $r['blank']);
		$this->assertStringContainsString('Load preview', $r['blank']);
		$this->assertStringNotContainsString('pd-page', $r['blank'], 'the page picker only exists once a sitemap has been stored');
	}

	public function testProductsGroupEveryEnvironmentUnderOnePublishedHost():void {
		// The rows are what the fleet caches hand over: one record per discovered host, from several nodes.
		$src = <<<'PHLO'
		$rows = [
			['app' => 'phlo.tech', 'host' => 'stage.phlo.tech', 'env' => 'prod', 'node' => 'local', 'visitors' => 14, 'seo' => true, 'indexable' => false],
			['app' => 'phlo.tech', 'host' => 'dev.phlo.tech', 'env' => 'dev', 'node' => 'local', 'visitors' => 0, 'seo' => true, 'indexable' => false],
			['app' => 'phlo.tech', 'host' => 'phlo.tech', 'env' => 'prod', 'node' => 'qai', 'visitors' => 1652, 'seo' => true, 'indexable' => true],
			['app' => 'logbook.tools', 'host' => 'logbook.tools', 'env' => 'prod', 'node' => 'qai', 'visitors' => 673, 'seo' => true, 'indexable' => true],
			['app' => 'logbook.tools', 'host' => 'jumplog.nl', 'env' => 'prod', 'node' => 'qai', 'visitors' => 177, 'seo' => true, 'indexable' => true, 'redirect' => true],
			['app' => 'files', 'host' => 'files.q-ai.nl', 'env' => 'prod', 'node' => 'qai', 'visitors' => 3, 'seo' => false, 'indexable' => false],
		]
		$out = product::group($rows)
		return ['apps' => array_keys($out), 'face' => loop($out, fn($p) => $p['face']['host']), 'layers' => loop($out, fn($p) => loop($p['layers'], fn($l) => $l['host'].':'.product::envLabel($l)))]
		PHLO;
		[$code, $out, $err] = self::cli('phlo_eval', $src);
		$this->assertSame(0, $code, "phlo_eval failed:\n$out$err");
		$r = json_decode(trim($out), true);
		$this->assertIsArray($r, "product::group output decoded:\n$out");
		// An app with no published host is not a product; the rest sort by the visitors of their public face.
		$this->assertSame(['phlo.tech', 'logbook.tools'], $r['apps'], 'only apps with a published host become products, busiest first');
		// The published host is the face even though its stage and dev siblings live on another node.
		$this->assertSame('phlo.tech', $r['face']['phlo.tech']);
		$this->assertSame(['stage.phlo.tech:staging', 'dev.phlo.tech:dev'], $r['layers']['phlo.tech'], 'the other environments become layers on that one card, staging before dev');
		// A redirect alias never wins the face, it hangs underneath as a layer.
		$this->assertSame('logbook.tools', $r['face']['logbook.tools']);
		$this->assertSame(['jumplog.nl:redirect'], $r['layers']['logbook.tools']);
	}
}
