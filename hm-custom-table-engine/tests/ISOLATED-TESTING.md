# Isolated Docker testing

This environment is separate from every LocalWP site. It binds only `127.0.0.1:18081`, uses its own Docker volumes, and mounts only this plugin folder. Docker Engine with Compose v2 is required. Docker and Podman were not available on the authoring host, so the commands below have **not** been executed.

Run from `D:\Custom Database Tables & Query Optimization\hm-custom-table-engine` in **PowerShell**. First inspect the resolved Compose configuration with `docker compose config`, then run:

```sh
docker compose up -d db wordpress
docker compose run --rm cli core install --url=http://127.0.0.1:18081 --title='HM CTE isolated test' --admin_user=hmtestadmin --admin_password='replace-with-local-test-password' --admin_email=hmtest@example.invalid --skip-email
docker compose run --rm cli plugin activate hm-custom-table-engine
docker compose run --rm cli eval 'global $wpdb; echo PHP_VERSION . " / " . $wpdb->db_version() . PHP_EOL; $db = new HM_Database( $wpdb ); var_export( $db->ready() ); echo PHP_EOL;'
docker compose run --rm cli eval-file wp-content/plugins/hm-custom-table-engine/tests/integration.php
```

The integration test creates and removes only its own posts and leads. Review its PASS/FAIL output; a PHP syntax check is not a substitute. For an isolated benchmark, run:

```sh
docker compose run --rm cli eval-file wp-content/plugins/hm-custom-table-engine/tests/benchmark-cli.php | Set-Content -LiteralPath '.\benchmark-primary.json' -Encoding utf8
```

The runner seeds 5,000 leads and 5,000 posts, runs six workloads, prints raw samples and summaries, then removes fixtures. Inspect the command's exit status before accepting the saved output. For a separate database timing pass, set `$env:HM_CTE_SAVEQUERIES = 'true'`, repeat the command with a different output filename, then run `Remove-Item Env:HM_CTE_SAVEQUERIES`. Compose passes that environment value into WordPress before bootstrap. The first measured sample includes captured SQL in the diagnostic pass. Use the isolated database container to run `EXPLAIN` and `EXPLAIN ANALYZE` on those actual statements, outside timed runs. Do not mix logged diagnostic timings into primary wall-time averages.

For coding standards, run `docker compose run --rm standards`. The service installs PHP_CodeSniffer and WordPress Coding Standards from this project's `composer.json`, then checks the plugin and test files. Review its output and exit code; no pass is claimed in advance.

For deactivation retention, create a lead through WP-CLI, record its ID, run `docker compose run --rm cli plugin deactivate hm-custom-table-engine`, and query the custom table from the database container. Reactivate and verify the same ID remains. For default uninstall retention, deactivate again and run `docker compose run --rm cli plugin uninstall hm-custom-table-engine --skip-delete`. The `--skip-delete` flag invokes uninstall while preserving the read-only mounted plugin files. Verify the table and record still exist. Opt-in destructive uninstall requires a separate fresh disposable stack and an explicitly set Boolean `hm_cte_delete_data_on_uninstall` option.

After saving evidence, remove only this Compose project's containers and volumes:

```sh
docker compose down -v --remove-orphans
```

Never point the Compose services at an existing website or database. The fixed Compose project name is `hm-cte-isolated`; `down -v` deletes only this project's named volumes when run with this configuration. Replace the sample administrator password before running the stack; it is only for a local disposable instance.
