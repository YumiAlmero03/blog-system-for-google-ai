<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-storage.php';
require_once __DIR__ . '/includes/playnow-promos.php';
require_once __DIR__ . '/includes/playnow-tracker-helpers.php';
require_once __DIR__ . '/includes/admin-date.php';

require_auth();

// Build memoized ignored-IP checker once, read storage once, and only write if pruning is needed.
$isIgnored = playnow_tracker_ignored_matcher();
$data = playnow_promo_data();
playnow_tracker_sync_ignored($data, $isIgnored);

$periods = tracker_periods();
$period = tracker_resolve_period($_GET['period'] ?? 'today');
$dashboard = tracker_build_dashboard($data, $period, $isIgnored);

$totalClicks = $dashboard['totalClicks'];
$updatedAt = $dashboard['updatedAt'];
$byPage = $dashboard['byPage'];
$byButton = $dashboard['byButton'];
$byIp = $dashboard['byIp'];
$byTarget = $dashboard['byTarget'];
$recent = $dashboard['recent'];
$chart = $dashboard['chart'];

$notice = (string) ($_SESSION['tracker_notice'] ?? '');
unset($_SESSION['tracker_notice']);

// Precompute SVG bar chart geometry server-side for instant first paint (zero external chart libs).
$chartBuckets = $chart['buckets'];
$chartCount = count($chartBuckets);
$chartMax = (int) $chart['maxCount'];
$yTicks = tracker_chart_y_ticks($chartMax);
$yMax = max(1, (int) ($yTicks[0] ?? 4));
$svgW = 920;
$svgH = 232;
$padL = 46;
$padR = 16;
$padT = 20;
$padB = 34;
$plotW = $svgW - $padL - $padR;
$plotH = $svgH - $padT - $padB;
$slotW = $chartCount > 0 ? ($plotW / $chartCount) : $plotW;
$barW = max(4.0, min(34.0, $slotW * 0.62));
$labelStep = $chartCount <= 12 ? 1 : ($chartCount <= 24 ? 3 : ($chartCount <= 35 ? 5 : (int) ceil($chartCount / 8)));
$displayTz = admin_display_timezone();
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta name="robots" content="noindex, nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Play Now Tracker | Admin</title>
  <link rel="preload" href="/admin/style.css" as="style"><link rel="stylesheet" href="/admin/style.css">
  <link rel="icon" href="/assets/favicon.svg">
  <style>
    .admin-container {
      max-width: 1180px;
      margin: 40px auto;
      padding: 24px;
      background: var(--surface);
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow);
    }
    .admin-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 2px solid var(--border);
    }
    .metric-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }
    .metric-card,
    .tracker-card {
      background: var(--surface-soft);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 16px;
    }
    .metric-card strong {
      display: block;
      font-size: 1.8rem;
      color: var(--brand-dark);
      line-height: 1.1;
    }
    .metric-card span {
      display: block;
      color: var(--text-muted);
      font-size: 0.82rem;
      margin-top: 4px;
    }
    .chart-card {
      margin-bottom: 18px;
    }
    .chart-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 12px;
    }
    .chart-meta {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
      font-size: 0.8rem;
    }
    .chart-pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 9px;
      border-radius: 999px;
      background: #fff;
      border: 1px solid var(--border);
      color: var(--text-muted);
      font-weight: 700;
    }
    .chart-pill strong {
      color: var(--brand-dark);
    }
    .chart-pill--accent {
      background: #fff8ea;
      border-color: #f3d7a3;
      color: var(--brand-dark);
    }
    .chart-wrap {
      position: relative;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 12px 10px 6px;
    }
    .chart-svg {
      display: block;
      width: 100%;
      height: auto;
      overflow: visible;
    }
    .chart-grid-line {
      stroke: #eaecf0;
      stroke-width: 1;
      stroke-dasharray: 3 3;
    }
    .chart-axis-line {
      stroke: #d0d5dd;
      stroke-width: 1;
    }
    .chart-axis-label {
      fill: #667085;
      font-size: 11px;
      font-family: Arial, Helvetica, sans-serif;
    }
    .chart-bar-value {
      fill: #741f2b;
      font-size: 10px;
      font-weight: 700;
      font-family: Arial, Helvetica, sans-serif;
      pointer-events: none;
    }
    .chart-col {
      cursor: pointer;
      outline: none;
    }
    .chart-col .chart-hit {
      fill: transparent;
      transition: fill 0.12s ease;
    }
    .chart-col:hover .chart-hit,
    .chart-col:focus .chart-hit {
      fill: rgba(166, 47, 61, 0.06);
    }
    .chart-col .chart-bar {
      fill: var(--brand);
      transition: fill 0.12s ease;
    }
    .chart-col .chart-bar--peak {
      fill: var(--brand-dark);
    }
    .chart-col .chart-bar--empty {
      fill: #e4e7ec;
    }
    .chart-col:hover .chart-bar,
    .chart-col:focus .chart-bar {
      fill: var(--brand-dark);
    }
    .chart-tooltip {
      position: absolute;
      z-index: 10;
      pointer-events: none;
      background: #101828;
      color: #ffffff;
      padding: 7px 10px;
      border-radius: 6px;
      font-size: 0.78rem;
      line-height: 1.35;
      box-shadow: 0 8px 20px rgba(16, 24, 40, 0.22);
      white-space: nowrap;
      opacity: 0;
      transform: translate(-50%, -100%);
      transition: opacity 0.1s ease;
    }
    .chart-tooltip.is-visible {
      opacity: 1;
    }
    .chart-tooltip strong {
      display: block;
      font-size: 0.82rem;
    }
    .chart-empty-note {
      text-align: center;
      font-size: 0.82rem;
      color: var(--text-muted);
      margin: 6px 0 4px;
    }
    .tracker-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }
    .tracker-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
      background: #fff;
      border: 1px solid var(--border);
      font-size: 0.84rem;
    }
    .tracker-table th,
    .tracker-table td {
      border-bottom: 1px solid var(--border);
      padding: 9px;
      text-align: left;
      vertical-align: top;
    }
    .tracker-table th {
      background: #fff8ea;
      color: var(--brand-dark);
      font-weight: 900;
    }
    .value-cell {
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    .recent-card {
      grid-column: 1 / -1;
    }
    @media (max-width: 860px) {
      .admin-header,
      .tracker-grid {
        grid-template-columns: 1fr;
      }
      .admin-header {
        flex-direction: column;
      }
    }
  </style>
</head>
<body>
  <div class="page-shell">
    <?php require __DIR__ . '/partials/admin-header.php'; ?>

    <main id="main-content" style="padding: 20px 16px;">
      <div class="admin-container">
        <div class="admin-header">
          <div>
            <h1 style="font-size:1.6rem; color:var(--brand-dark);">Play Now Tracker</h1>
            <p style="font-size:0.88rem; color:var(--text-muted);">Track every Play Now click, button label, source page, target URL, and user IP.</p>
          </div>
          <a href="/admin/blogs.php" class="btn btn-secondary btn-sm">Back to Blogs</a>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
          <?php foreach ($periods as $key => $label): ?><a class="btn <?= $period === $key ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="?period=<?= h($key) ?>"><?= h($label) ?></a><?php endforeach; ?>
          <a class="btn btn-secondary btn-sm" href="/admin/playnow-promos.php">Manage promo links</a>
        </div>
        <p style="font-size:0.82rem; color:var(--text-muted); margin:0 0 18px;">Showing: <?= h($periods[$period]) ?><?= $period !== 'all' ? ' (based on recorded click events)' : '' ?></p>

        <div class="metric-grid">
          <div class="metric-card">
            <strong><?= h(number_format($totalClicks)) ?></strong>
            <span>Total tracked clicks</span>
          </div>
          <div class="metric-card">
            <strong><?= h(number_format(count($byPage))) ?></strong>
            <span>Source pages</span>
          </div>
          <div class="metric-card">
            <strong><?= h(number_format(count($byButton))) ?></strong>
            <span>Button labels</span>
          </div>
          <div class="metric-card">
            <strong><?= h(number_format(count($byIp))) ?></strong>
            <span>User IPs</span>
          </div>
        </div>

        <section class="tracker-card chart-card" aria-label="<?= h($chart['title']) ?>">
          <div class="chart-header">
            <div>
              <h2 style="font-size:1.05rem; color:var(--brand-dark); margin:0 0 2px;"><?= h($chart['title']) ?></h2>
              <p style="font-size:0.8rem; color:var(--text-muted); margin:0;"><?= h($chart['subtitle']) ?></p>
            </div>
            <div class="chart-meta">
              <span class="chart-pill chart-pill--accent"><?= h($chart['granularityLabel']) ?></span>
              <?php if ($chart['peakCount'] > 0): ?>
                <span class="chart-pill">Peak: <strong><?= h($chart['peakLabel']) ?></strong> (<?= h(number_format($chart['peakCount'])) ?>)</span>
              <?php else: ?>
                <span class="chart-pill">No clicks in this period</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="chart-wrap" id="tracker-chart-wrap">
            <svg
              class="chart-svg"
              id="tracker-chart-svg"
              viewBox="0 0 <?= $svgW ?> <?= $svgH ?>"
              role="img"
              aria-label="<?= h($chart['title'] . ' — ' . $chart['subtitle'] . ': ' . number_format($chart['totalClicks']) . ' clicks') ?>"
            >
              <?php foreach ($yTicks as $tick):
                  $y = $padT + $plotH - (($tick / $yMax) * $plotH);
              ?>
                <line class="<?= $tick === 0 ? 'chart-axis-line' : 'chart-grid-line' ?>" x1="<?= $padL ?>" y1="<?= number_format($y, 1, '.', '') ?>" x2="<?= $svgW - $padR ?>" y2="<?= number_format($y, 1, '.', '') ?>" />
                <text class="chart-axis-label" x="<?= $padL - 8 ?>" y="<?= number_format($y + 4, 1, '.', '') ?>" text-anchor="end"><?= h(number_format($tick)) ?></text>
              <?php endforeach; ?>

              <?php foreach ($chartBuckets as $idx => $bucket):
                  $count = (int) $bucket['count'];
                  $slotX = $padL + ($idx * $slotW);
                  $centerX = $slotX + ($slotW / 2);
                  $barX = $centerX - ($barW / 2);
                  $barH = $count > 0 ? max(4.0, ($count / $yMax) * $plotH) : 2.0;
                  $barY = $padT + $plotH - $barH;
                  $share = $chart['totalClicks'] > 0 ? round(($count / $chart['totalClicks']) * 100, 1) : 0.0;
                  $isPeak = $chartMax > 0 && $count === $chartMax;
                  $barClass = $count === 0 ? 'chart-bar chart-bar--empty' : ($isPeak ? 'chart-bar chart-bar--peak' : 'chart-bar');
                  $showXLabel = ($idx === 0) || ($idx === $chartCount - 1) || ($idx % $labelStep === 0 && ($chartCount - 1 - $idx) >= max(1, (int) floor($labelStep / 2)));
              ?>
                <g
                  class="chart-col"
                  tabindex="0"
                  data-label="<?= h($bucket['fullLabel']) ?>"
                  data-count="<?= $count ?>"
                  data-share="<?= h(number_format($share, 1)) ?>"
                  data-cx="<?= number_format($centerX, 1, '.', '') ?>"
                  data-top="<?= number_format($barY, 1, '.', '') ?>"
                >
                  <title><?= h($bucket['fullLabel'] . ': ' . number_format($count) . ' click' . ($count === 1 ? '' : 's') . ($chart['totalClicks'] > 0 ? ' (' . number_format($share, 1) . '%)' : '')) ?></title>
                  <rect class="chart-hit" x="<?= number_format($slotX, 1, '.', '') ?>" y="<?= $padT ?>" width="<?= number_format($slotW, 1, '.', '') ?>" height="<?= $plotH ?>" rx="3" />
                  <rect class="<?= $barClass ?>" x="<?= number_format($barX, 1, '.', '') ?>" y="<?= number_format($barY, 1, '.', '') ?>" width="<?= number_format($barW, 1, '.', '') ?>" height="<?= number_format($barH, 1, '.', '') ?>" rx="<?= $count > 0 ? 3 : 1 ?>" />
                  <?php if ($count > 0 && ($chartCount <= 14 || $isPeak)): ?>
                    <text class="chart-bar-value" x="<?= number_format($centerX, 1, '.', '') ?>" y="<?= number_format(max(12.0, $barY - 5), 1, '.', '') ?>" text-anchor="middle"><?= h(number_format($count)) ?></text>
                  <?php endif; ?>
                </g>
                <?php if ($showXLabel): ?>
                  <text class="chart-axis-label" x="<?= number_format($centerX, 1, '.', '') ?>" y="<?= $svgH - 10 ?>" text-anchor="middle"><?= h($bucket['label']) ?></text>
                <?php endif; ?>
              <?php endforeach; ?>
            </svg>
            <div class="chart-tooltip" id="tracker-chart-tooltip" role="tooltip" aria-hidden="true"></div>
            <?php if ($chart['totalClicks'] === 0): ?>
              <p class="chart-empty-note">No tracked clicks recorded for <?= h($periods[$period]) ?> yet.</p>
            <?php endif; ?>
          </div>
        </section>

        <div class="tracker-grid">
          <section class="tracker-card">
            <h2 style="font-size:1.05rem; color:var(--brand-dark);">Top Pages</h2>
            <table class="tracker-table" data-paginate>
              <thead><tr><th>Page</th><th>Clicks</th></tr></thead>
              <tbody>
                <?php foreach (tracker_top($byPage) as $value => $count): ?>
                  <tr><td class="value-cell"><?= h($value) ?></td><td><?= (int) $count ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>

          <section class="tracker-card">
            <h2 style="font-size:1.05rem; color:var(--brand-dark);">Top Buttons</h2>
            <table class="tracker-table" data-paginate>
              <thead><tr><th>Button</th><th>Clicks</th></tr></thead>
              <tbody>
                <?php foreach (tracker_top($byButton) as $value => $count): ?>
                  <tr><td class="value-cell"><?= h($value) ?></td><td><?= (int) $count ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>

          <section class="tracker-card">
            <h2 style="font-size:1.05rem; color:var(--brand-dark);">Top Targets</h2>
            <table class="tracker-table" data-paginate>
              <thead><tr><th>Target URL</th><th>Clicks</th></tr></thead>
              <tbody>
                <?php foreach (tracker_top($byTarget) as $value => $count): ?>
                  <tr><td class="value-cell"><?= h($value) ?></td><td><?= (int) $count ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>

          <section class="tracker-card">
            <h2 style="font-size:1.05rem; color:var(--brand-dark);">Top IPs</h2>
            <table class="tracker-table" data-paginate>
              <thead><tr><th>IP</th><th>Clicks</th></tr></thead>
              <tbody>
                <?php foreach (tracker_top($byIp) as $value => $count): ?>
                  <tr><td class="value-cell"><?= h($value) ?></td><td><?= (int) $count ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>

          <section class="tracker-card recent-card">
            <h2 style="font-size:1.05rem; color:var(--brand-dark);">Recent Clicks</h2>
            <p style="font-size:0.82rem; color:var(--text-muted);">Last updated: <?= h($updatedAt !== '' ? (admin_format_date($updatedAt) ?: $updatedAt) : 'No clicks yet') ?></p>
            <table class="tracker-table" data-paginate>
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Page</th>
                  <th>Button</th>
                  <th>Section</th>
                  <th>IP</th>
                  <th>Target</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recent as $event):
                    $eventTs = tracker_event_timestamp($event);
                    $formattedTime = $eventTs !== null
                        ? (new DateTimeImmutable('@' . $eventTs))->setTimezone($displayTz)->format('M j, Y, g:i A')
                        : (admin_format_date($event['time'] ?? '') ?: (string) ($event['time'] ?? ''));
                ?>
                  <tr>
                    <td><time datetime="<?= h($event['time'] ?? '') ?>"><?= h($formattedTime) ?></time></td>
                    <td class="value-cell"><?= h($event['pagePath'] ?? ($event['pageUrl'] ?? '')) ?></td>
                    <td class="value-cell"><?= h($event['buttonText'] ?? '') ?></td>
                    <td class="value-cell"><?= h($event['section'] ?? ($event['location'] ?? '')) ?></td>
                    <td><?= h($event['ip'] ?? '') ?></td>
                    <td class="value-cell"><?= h($event['targetUrl'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>
        </div>
      </div>
    </main>
  </div>
  <script>
    (() => {
      const svg = document.getElementById('tracker-chart-svg');
      const wrap = document.getElementById('tracker-chart-wrap');
      const tip = document.getElementById('tracker-chart-tooltip');
      if (!svg || !wrap || !tip) return;

      const showTip = (col) => {
        const label = col.getAttribute('data-label') || '';
        const count = Number(col.getAttribute('data-count') || '0');
        const share = col.getAttribute('data-share') || '0.0';
        const cx = Number(col.getAttribute('data-cx') || '0');
        const top = Number(col.getAttribute('data-top') || '0');
        const wrapRect = wrap.getBoundingClientRect();
        const svgRect = svg.getBoundingClientRect();
        const scaleX = svgRect.width / <?= $svgW ?>;
        const scaleY = svgRect.height / <?= $svgH ?>;
        const left = (svgRect.left - wrapRect.left) + (cx * scaleX);
        const posTop = (svgRect.top - wrapRect.top) + (top * scaleY) - 8;

        tip.textContent = '';
        const strong = document.createElement('strong');
        strong.textContent = label;
        const line = document.createElement('span');
        line.textContent = count.toLocaleString() + (count === 1 ? ' click' : ' clicks') + (count > 0 ? ' (' + share + '%)' : '');
        tip.append(strong, line);

        const clampedLeft = Math.max(78, Math.min(wrapRect.width - 78, left));
        tip.style.left = clampedLeft + 'px';
        tip.style.top = Math.max(38, posTop) + 'px';
        tip.classList.add('is-visible');
        tip.setAttribute('aria-hidden', 'false');
      };

      const hideTip = () => {
        tip.classList.remove('is-visible');
        tip.setAttribute('aria-hidden', 'true');
      };

      svg.addEventListener('mouseover', (event) => {
        const col = event.target.closest('.chart-col');
        if (col) showTip(col);
      });
      svg.addEventListener('mouseleave', hideTip);
      svg.addEventListener('focusin', (event) => {
        const col = event.target.closest('.chart-col');
        if (col) showTip(col);
      });
      svg.addEventListener('focusout', hideTip);
    })();
  </script>
</body>
</html>
