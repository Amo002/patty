<?php

return [

    /*
    | D-035: delivery tolerance defaults per unit, in basis points (500 = 5%).
    | An ingredient may override any of the three values; null fields fall back
    | to the entry for its unit. A null cap means no absolute cap (pieces are
    | counted, so the percentage rounding already keeps small counts exact).
    */
    'tolerance' => [
        'defaults' => [
            'g' => ['over_bps' => 500, 'under_bps' => 500, 'over_cap' => 2000],
            'ml' => ['over_bps' => 500, 'under_bps' => 500, 'over_cap' => 2000],
            'piece' => ['over_bps' => 500, 'under_bps' => 500, 'over_cap' => null],
        ],
    ],

];
