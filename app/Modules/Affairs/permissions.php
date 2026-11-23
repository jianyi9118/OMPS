<?php
/**
 * My affairs (phase 5). To-dos, the notebook, received items and notifications need no permission
 * (every signed-in account has them). send = issue tips, documents and orders to people in my
 * scope; rules = adjust reminder rules (design §13.3, the unit head may adjust them).
 */
return array(
    array('code' => 'issue.send', 'group' => 'issue', 'action' => 'add', 'roles' => array('unit_head', 'dept_head')),
    array('code' => 'notify.rules', 'group' => 'notify', 'action' => 'edit', 'roles' => array('unit_head')),
);
