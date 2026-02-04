<?php

namespace Riclep\Storyblok\Fields;

use Riclep\Storyblok\Field;
use Riclep\Storyblok\Support\HardBreak;
use Riclep\Storyblok\Traits\HasChildClasses;
use Tiptap\Editor;
use Storyblok\Tiptap\Extension\Storyblok;

class RichText extends Field
{
	use HasChildClasses;

	public function init(): void
	{
        if (config('storyblok.tiptap.extensions')) {
            $editor = new Editor(config('storyblok.tiptap'));
        } else {
            $editor = new Editor(
                [
                    'extensions' => [
                        new Storyblok(),
                        new HardBreak() // support hard_break in TipTap json
                    ]
                ]
            );
        }

		$content = [];
		$pendingNodes = [];

		// Helper function to flush pending nodes to HTML
		$flushPendingNodes = function() use ($editor, &$content, &$pendingNodes) {
			if (!empty($pendingNodes)) {
				$editor->setContent(['type' => 'doc', 'content' => $pendingNodes]);
				$content[] = $editor->getHTML();
				$pendingNodes = [];
			}
		};

		// Loop through all the nodes looking for 'blok' nodes and convert them to
		// the correct Block Class. All other nodes are batched together for HTML conversion
		foreach ($this->content['content'] as $node) {
			if ($node['type'] === 'blok' && isset($node['attrs']['body']) && is_array($node['attrs']['body'])) {
				// Flush any pending non-blok nodes first
				$flushPendingNodes();

				foreach ($node['attrs']['body'] as $blockContent) {
					$class = $this->getChildClassName('Block', $blockContent['component']);
					$block = new $class($blockContent, $this->block());

					$content[] = $block;
				}
			} else {
				// Batch non-blok nodes together to preserve whitespace between them
				$pendingNodes[] = $node;
			}
		}

		// Flush any remaining pending nodes
		$flushPendingNodes();

		$this->content = collect($content);
	}

	/**
	 * Converts the data to HTML when printed. If there is an inline Component
	 * it will use it’s render method.
	 *
	 * @return string
	 */
	public function __toString(): string
	{
		$html = "";

		foreach ($this->content as $content) {
			if (is_string($content)) {
				$html .= $content;
			} else {
				$html .= $content->render();
			}
		}

		// Fix missing spaces around inline elements (strong, em, a, etc.)
		// This handles cases where TipTap doesn't preserve whitespace at mark boundaries
		$html = $this->fixInlineElementSpacing($html);

		return $html;
	}

	/**
	 * Fix missing spaces around inline elements.
	 * TipTap sometimes loses whitespace at the boundaries of marked text.
	 *
	 * @param string $html
	 * @return string
	 */
	protected function fixInlineElementSpacing(string $html): string
	{
		// Pattern to find inline elements that are missing spaces
		// Matches: word character immediately followed by opening tag
		$inlineTags = 'strong|em|b|i|u|s|a|code|span|mark';

		// Add space before inline element if preceded by word character
		$html = preg_replace(
			'/(\w)(<(?:' . $inlineTags . ')(?:\s[^>]*)?>)/i',
			'$1 $2',
			$html
		);

		// Add space after inline element if followed by word character
		$html = preg_replace(
			'/(<\/(?:' . $inlineTags . ')>)(\w)/i',
			'$1 $2',
			$html
		);

		return $html;
	}
}
