<?php

declare(strict_types=1);

namespace Riclep\Storyblok\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Riclep\Storyblok\Page;

class PreviewController
{
    /**
     * Render story content from Storyblok draft data for live preview.
     */
    public function render(Request $request): JsonResponse
    {
        $storyData = $request->input('story');


        // Validate this is a legitimate preview request by checking:
        // 1. Story data contains _editable (only present in draft mode from Storyblok)
        // 2. Request comes from Storyblok app (referer check)
        $isValidPreview = false;

        if (is_array($storyData)) {
            $content = $storyData['content'] ?? [];
            $hasEditable = isset($content['_editable']);
            $referer = $request->header('referer', '');
            $fromStoryblok = str_contains($referer, 'app.storyblok.com')
                || str_contains($referer, '_storyblok=');

            $isValidPreview = $hasEditable || $fromStoryblok;
        }

        if (!$isValidPreview) {
            return response()->json(['error' => 'Preview not available'], 403);
        }

        // Enable edit mode so blocks render with editor links
        config(['storyblok.edit_mode' => true]);

        if (!$storyData || !is_array($storyData)) {
            return response()->json(['error' => 'Invalid story data'], 400);
        }

        try {
            $page = new Page($storyData);
            $blocks = $page->block()->body ?? collect();

            $renderedBlocks = [];
            foreach ($blocks as $block) {

                $renderedBlocks[] = [
                    'uid' => $block->meta('_uid'),
                    'html' => $block->render()->render(),
                ];
            }

            return response()->json([
                'success' => true,
                'blocks' => $renderedBlocks,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to render content: ' . $e->getMessage(),
            ], 500);
        }
    }
}
