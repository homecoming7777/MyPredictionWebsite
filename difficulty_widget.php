<?php

/**
 * Match difficulty display for prediction pages.
 *
 * Expected:
 * - int $widget_match_id
 * - array $widget_difficulty (from difficultyGetForMatch)
 */

if (!isset($widget_match_id, $widget_difficulty) || !function_exists('e')) {
    return;
}

$widget_match_id = (int)$widget_match_id;

if ($widget_match_id <= 0) {
    return;
}

$score = $widget_difficulty['score'] ?? null;
$label = (string)($widget_difficulty['label'] ?? 'Not enough data');
$display = (string)($widget_difficulty['display'] ?? 'Not enough data');
$predictorCount = (int)($widget_difficulty['predictor_count'] ?? 0);

$barWidth = $score !== null ? max(8, min(100, (int)$score * 10)) : 0;

?>
<div class="match-difficulty mt-3 mx-auto max-w-[650px] rounded-xl border border-[#ff9900]/25 bg-black/30 px-4 py-3">
    <div class="flex items-center justify-between gap-3">
        <div class="text-[10px] uppercase tracking-wider font-black text-[#ff9900]">
            Match Difficulty
        </div>
        <?php if ($score !== null): ?>
            <div class="text-xs font-black text-white tabular-nums">
                <?= e($display) ?>
            </div>
        <?php else: ?>
            <div class="text-xs font-bold text-gray-400">
                <?= e($display) ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($score !== null): ?>
        <div class="mt-2 h-2 rounded-full bg-white/10 overflow-hidden">
            <div class="h-full rounded-full bg-gradient-to-r from-green-400 via-yellow-400 to-red-500" style="width: <?= (int)$barWidth ?>%;"></div>
        </div>
        <div class="mt-2 text-[11px] text-gray-400">
            Based on <?= $predictorCount ?> community prediction<?= $predictorCount === 1 ? '' : 's' ?>.
            Split:
            Home <?= (float)($widget_difficulty['home_pct'] ?? 0) ?>% ·
            Draw <?= (float)($widget_difficulty['draw_pct'] ?? 0) ?>% ·
            Away <?= (float)($widget_difficulty['away_pct'] ?? 0) ?>%
        </div>
    <?php else: ?>
        <div class="mt-2 text-[11px] text-gray-500">
            Difficulty appears once players start predicting this match.
        </div>
    <?php endif; ?>
</div>
