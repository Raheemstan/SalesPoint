<?php

it('sends the root URL to the POS (which then requires login)', function () {
    $this->get('/')->assertRedirect(route('pos.index'));
    $this->get(route('pos.index'))->assertRedirect(route('login'));
});
