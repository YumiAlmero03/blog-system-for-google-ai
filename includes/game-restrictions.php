<?php
declare(strict_types=1);

/** Exact country tokens, including the API's existing PHILIPPINES alias. */
function games_ph_allowed_sql(): string
{
    return "NOT EXISTS (
        SELECT 1
        FROM json_each(CASE WHEN json_valid(restrictions) THEN restrictions
                            ELSE json_array(COALESCE(restrictions, '')) END)
        WHERE instr(',' || replace(upper(value), ' ', '') || ',', ',PH,') > 0
           OR instr(',' || replace(upper(value), ' ', '') || ',', ',PHILIPPINES,') > 0
    )";
}
