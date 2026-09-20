<?php

/**
 * Reaction bar for post-deadline community match pages.
 *
 * Expected:
 * - int $widget_match_id
 * - array $widget_reaction_summary
 * - bool $widget_reactions_enabled
 * - int $widget_predictor_count (optional)
 */

if (!isset($widget_match_id, $widget_reaction_summary) || !function_exists('e')) {
    return;
}

$widget_match_id = (int)$widget_match_id;
$widget_reactions_enabled = !empty($widget_reactions_enabled);
$widget_predictor_count = (int)($widget_predictor_count ?? 0);
$minimumPredictors = function_exists('reactionsMinimumPredictors') ? reactionsMinimumPredictors() : 3;

if ($widget_match_id <= 0) {
    return;
}

?>
<div
    class="match-reactions mt-4 rounded-xl border border-white/10 bg-black/25 px-4 py-3"
    data-reactions-root="<?= (int)$widget_match_id ?>"
>
    <div class="flex items-center justify-between gap-3 mb-2">
        <div class="text-[10px] uppercase tracking-wider font-black text-[#ff0080]">
            Community Reactions
        </div>
        <?php if ($widget_reactions_enabled): ?>
            <div class="text-[10px] text-gray-400">
                <?= $widget_predictor_count ?> predictors · visible to everyone
            </div>
        <?php else: ?>
            <div class="text-[10px] text-gray-500">
                Unlocks after <?= (int)$minimumPredictors ?> players predict (<?= $widget_predictor_count ?>/<?= (int)$minimumPredictors ?>)
            </div>
        <?php endif; ?>
    </div>

    <?php if ($widget_reactions_enabled): ?>
        <div class="flex flex-wrap items-center justify-center gap-2">
            <?php foreach ($widget_reaction_summary as $type => $reaction): ?>
                <?php
                $isActive = !empty($reaction['active']);
                $count = (int)($reaction['count'] ?? 0);
                $users = $reaction['users'] ?? [];
                ?>
                <div class="flex flex-col items-center gap-1">
                    <button
                        type="button"
                        class="reaction-btn inline-flex items-center gap-1.5 px-3 py-2 rounded-full border text-sm font-black transition transform hover:scale-105 active:scale-95 <?= $isActive ? 'bg-[#ff0080]/25 border-[#ff0080]/60 text-white shadow-[0_0_15px_rgba(255,0,128,0.25)]' : 'bg-black/25 border-white/15 text-gray-200 hover:border-[#ff9900]/50' ?>"
                        data-match-id="<?= (int)$widget_match_id ?>"
                        data-reaction-type="<?= e($type) ?>"
                        title="<?= e($reaction['label'] ?? $type) ?>"
                        aria-label="<?= e($reaction['label'] ?? $type) ?>"
                        aria-pressed="<?= $isActive ? 'true' : 'false' ?>"
                    >
                        <span class="text-lg leading-none"><?= e($reaction['emoji'] ?? '') ?></span>
                        <span class="reaction-count text-xs tabular-nums"><?= $count ?></span>
                    </button>
                    <?php if (!empty($users)): ?>
                        <div class="reaction-users text-[10px] text-gray-400 max-w-[140px] text-center leading-tight">
                            <?= e(implode(', ', array_column($users, 'username'))) ?>
                        </div>
                    <?php else: ?>
                        <div class="reaction-users text-[10px] text-gray-600">&nbsp;</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center text-xs text-gray-500 py-2">
            Reactions open once at least <?= (int)$minimumPredictors ?> users have predicted this match.
        </div>
    <?php endif; ?>
</div>
