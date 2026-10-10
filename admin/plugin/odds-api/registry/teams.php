<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_auth();
require_once __DIR__ . '/../includes/sports-registry.php';

$slug = trim((string) ($_GET['sport'] ?? ''));
$sport = $slug !== '' ? sports_category_get($slug) : null;

$sportId = $sport ? (int) $sport['id'] : 0;
$competitions = $sportId > 0 ? tournament_get_all($sportId) : tournament_get_all();

$selectedComp = isset($_GET['competition']) && is_numeric($_GET['competition']) ? (int) $_GET['competition'] : 0;
$search = trim((string) ($_GET['q'] ?? ''));

$participants = participant_get_all([
    'sports_category_id' => $sportId > 0 ? $sportId : null,
    'tournament_id' => $selectedComp > 0 ? $selectedComp : null,
    'search' => $search !== '' ? $search : null,
]);

$title = ($sport ? $sport['name'] . ' ' : '') . 'Participants';
require __DIR__ . '/../../../partials/support-head.php';
?>
<style>
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
.badge-on { background: #dcfce7; color: #166534; }
.badge-off { background: #fee2e2; color: #991b1b; }
.badge-manual { background: #fef3c7; color: #92400e; }
.badge-api { background: #e0f2fe; color: #0369a1; }
.badge-odds { background: #ede9fe; color: #5b21b6; }
.team-thumb { width: 34px; height: 34px; border-radius: 6px; object-fit: contain; background: #1e293b; vertical-align: middle; border: 1px solid var(--border); }
.team-thumb-empty { width: 34px; height: 34px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; background: var(--surface-soft); color: var(--text-muted); font-size: 0.72rem; border: 1px dashed var(--border); }
</style>

<div>
  <p><a href="/admin/plugin/odds-api/registry/">← Back to Sports Registry</a>
  <?php if ($sport): ?>
    · <a href="/admin/plugin/odds-api/registry/registration.php?sport=<?= h($sport['slug']) ?>">Setup & Tournaments</a>
    · <a href="/admin/plugin/odds-api/registry/edit.php?id=<?= $sportId ?>">Edit Category</a>
  <?php endif; ?>
  </p>

  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
    <h2><?= $sport ? h($sport['name']) . ' ' : '' ?>Participants (<?= count($participants) ?>)</h2>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0;">
      <?php if ($sport): ?>
        <input type="hidden" name="sport" value="<?= h($sport['slug']) ?>">
      <?php endif; ?>
      <select name="competition" onchange="this.form.submit()">
        <option value="0">All Tournaments</option>
        <?php foreach ($competitions as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $selectedComp === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['title']) ?> (<?= h($c['odds_sport_key']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search name or ID..." style="padding:4px 8px;">
      <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
      <?php if ($search !== '' || $selectedComp > 0): ?>
        <a href="/admin/plugin/odds-api/registry/teams.php<?= $sport ? '?sport=' . h($sport['slug']) : '' ?>" class="btn btn-secondary btn-sm">Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="support-table">
    <table data-paginate data-pagination-label="Participants">
      <thead>
        <tr>
          <th>Logo</th>
          <th>Participant Name</th>
          <th>Identifier</th>
          <th>Category</th>
          <th>Tournament(s)</th>
          <th>Image Key</th>
          <th>Source</th>
          <th>Status</th>
          <th>Updated</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$participants): ?>
          <tr><td colspan="10" style="text-align:center;padding:24px;color:var(--text-muted);">No participants found. Sync participants from The Odds API in the Sports Registry.</td></tr>
        <?php endif; ?>
        <?php foreach ($participants as $t): ?>
          <tr>
            <td>
              <?php if (!empty($t['image_path'])): ?>
                <?php $tImgUrl = sports_registry_safe_image_url($t['image_path']) . ($t['updated_at'] ? '?t=' . $t['updated_at'] : ''); ?>
                <img src="<?= h($tImgUrl) ?>" alt="<?= h($t['name']) ?>" class="team-thumb">
              <?php else: ?>
                <span class="team-thumb-empty"><?= $t['image_failure'] ? '⚠️' : '—' ?></span>
              <?php endif; ?>
            </td>
            <td><strong><a href="/admin/plugin/odds-api/registry/team-edit.php?id=<?= (int) $t['id'] ?>"><?= h($t['name']) ?></a></strong></td>
            <td><code><?= h($t['participant_id'] ?: $t['slug']) ?></code></td>
            <td><?= h($t['category_name']) ?></td>
            <td><small><?= h($t['tournaments_titles'] ?: ($t['odds_sport_key'] ?: '—')) ?></small></td>
            <td><code><small><?= h($t['image_key']) ?></small></code></td>
            <td>
              <?php if ($t['image_source'] === 'manual'): ?>
                <span class="badge badge-manual">MANUAL</span>
              <?php elseif ($t['image_source'] === 'api_sports'): ?>
                <span class="badge badge-api">API-SPORTS</span>
              <?php else: ?>
                <span class="badge badge-odds">ODDS API</span>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $t['enabled'] ? 'badge-on' : 'badge-off' ?>"><?= $t['enabled'] ? 'On' : 'Off' ?></span></td>
            <td><small><?= date('M j, Y', (int) $t['updated_at']) ?></small></td>
            <td>
              <a class="btn btn-secondary btn-sm" href="/admin/plugin/odds-api/registry/team-edit.php?id=<?= (int) $t['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</main></body></html>
