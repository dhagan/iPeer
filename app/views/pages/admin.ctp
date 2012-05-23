<div class='adminpage'>
<ul>
<?php
if (User::hasPermission('controllers/faculties')) {
    echo '<li>';
    echo $this->Html->link(
        'Faculties', 
        array('controller' => 'faculties')
    );
    echo '</li>';
}

if (User::hasPermission('controllers/departments')) {
    echo '<li>';
    echo $this->Html->link(
        'Departments', 
        array('controller' => 'departments')
    );
    echo '</li>';
}

// System Parameters
if (User::hasPermission('controllers/sysparameters')) {
    echo '<li>';
    echo $this->Html->link(
        'System Parameters', 
        array('controller' => 'sysparameters')
    );
    echo '</li>';
}

// System Functions
if (User::hasPermission('controllers/sysfunctions')) {
    echo '<li>';
    echo $this->Html->link(
        'System Functions', 
        array('controller' => 'sysfunctions')
    );
    echo '</li>';
}
?>
</ul>
</div>
