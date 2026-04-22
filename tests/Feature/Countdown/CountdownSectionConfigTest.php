<?php

it('registers countdown as a section_block_type', function () {
    expect(config('sidest.section_block_types'))->toContain('countdown');
});
