<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Index;

interface EntityDocumentCompilerInterface
{
    /** @return array<int, array{entity_id:int, primary_text:string, secondary_text:string}> */
    public function compileAll(): array;

    /** @param int[] $ids  @return array<int, array{entity_id:int, primary_text:string, secondary_text:string}> */
    public function compileByIds(array $ids): array;
}
