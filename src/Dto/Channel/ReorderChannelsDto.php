<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use Symfony\Component\HttpFoundation\Request;

final readonly class ReorderChannelsDto
{
    /**
     * @param list<int> $order
     */
    public function __construct(
        public array $order,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $rawOrder = null;

        // 1. Try JSON body
        $contentType = (string) $request->headers->get('Content-Type', '');
        if (str_contains($contentType, 'application/json') || $request->getContent() !== '') {
            $data = json_decode($request->getContent(), true);
            if (is_array($data) && array_key_exists('order', $data) && is_array($data['order'])) {
                $rawOrder = $data['order'];
            }
        }

        // 2. Try POST form parameters
        if ($rawOrder === null) {
            $postOrder = $request->request->all('order');
            if ($postOrder !== []) {
                $rawOrder = $postOrder;
            }
        }

        if ($rawOrder === null) {
            $all = $request->request->all();
            if (array_key_exists('order', $all) && is_array($all['order'])) {
                $rawOrder = $all['order'];
            }
        }

        if (!is_array($rawOrder)) {
            return new self([]);
        }

        $order = [];
        foreach ($rawOrder as $item) {
            if (!is_numeric($item)) {
                continue;
            }
            $order[] = (int) $item;
        }

        return new self($order);
    }

    public function isValid(): bool
    {
        return $this->order !== [];
    }
}
