<?php
/**
 * @var \Cake\View\View $this
 * @var array $loginAction
 * @var bool $remember
 */
echo $this->Flash->render('two-factor-auth');
echo $this->Form->create(null, ['url' => $loginAction]);
echo $this->Form->control('code', ['label' => __('Verification code')]);
if ($remember) {
    echo $this->Form->control('remember', ['type' => 'checkbox', 'label' => __('Trust this device for the next 30 days')]);
}
echo $this->Form->button(__('Verify'));
echo $this->Form->end();
