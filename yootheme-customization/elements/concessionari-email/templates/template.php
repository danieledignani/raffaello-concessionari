<?php

    $el = $this->el('div');
    echo $el($props, $attrs);

    if ($props['content']) {
        $email = $props['content'];

        echo '<div class="email-row"><div class="label"><strong>Email:</strong></div><div class="email">'.$email.'</div></div>';
    }


    echo $el->end();
