<?php

declare(strict_types=1);

namespace App\Services\Ems\Kiosk;

/**
 * EMS shorthand the supply room screen understands, sent with the item list so search can expand what a member
 * types (docs/ems/supply-room-kiosk.md section 6). Supplies only: controlled medications never show on the screen.
 * Words Captain 5 approves from real searches are added per item, not here.
 */
final class KioskShorthand
{
    /** @var array<string, list<string>> */
    public const MAP = [
        'nrb' => ['non rebreather', 'non-rebreather', 'nonrebreather mask'],
        'nc' => ['nasal cannula', 'cannula'],
        'bvm' => ['bag valve mask', 'ambu bag'],
        'opa' => ['oropharyngeal airway', 'oral airway'],
        'npa' => ['nasopharyngeal airway', 'nasal airway', 'nasal trumpet'],
        'ett' => ['endotracheal tube', 'et tube'],
        'igel' => ['i-gel', 'supraglottic airway'],
        'king' => ['king airway', 'supraglottic airway'],
        'cpap' => ['cpap mask', 'continuous positive airway pressure'],
        'neb' => ['nebulizer', 'neb kit', 'hand held nebulizer'],
        'io' => ['intraosseous', 'ez-io', 'ez io needle'],
        'ezio' => ['ez-io', 'intraosseous needle'],
        'iv' => ['iv catheter', 'angiocath', 'intravenous'],
        'angio' => ['iv catheter', 'angiocath'],
        'cath' => ['catheter', 'iv catheter'],
        'flush' => ['saline flush', 'prefilled flush'],
        'ns' => ['normal saline', 'sodium chloride 0.9'],
        'lr' => ['lactated ringers'],
        'tq' => ['tourniquet', 'cat tourniquet'],
        'cat' => ['tourniquet'],
        '4x4' => ['gauze', 'gauze pad', 'gauze sponge'],
        'abd' => ['abd pad', 'abdominal pad', 'trauma dressing'],
        'kerlix' => ['gauze roll', 'rolled gauze'],
        'coban' => ['self adherent wrap', 'elastic wrap'],
        'tegaderm' => ['iv dressing', 'transparent dressing'],
        'ekg' => ['ecg', 'electrodes', 'monitor electrodes'],
        'ecg' => ['ekg', 'electrodes', 'monitor electrodes'],
        'spo2' => ['pulse ox', 'pulse oximeter', 'sat probe'],
        'bp' => ['blood pressure cuff', 'bp cuff'],
        'glucometer' => ['glucose meter', 'blood glucose'],
        'lancet' => ['glucose lancet', 'finger stick'],
        'ob' => ['ob kit', 'obstetrical kit', 'childbirth kit'],
        'mci' => ['triage tags', 'mass casualty'],
        'n95' => ['respirator mask', 'n95 mask'],
        'ppe' => ['gloves', 'gown', 'mask', 'face shield'],
        'chux' => ['underpad', 'bed pad'],
        'emesis' => ['emesis bag', 'vomit bag'],
        'c collar' => ['cervical collar', 'neck collar'],
        'ked' => ['extrication device', 'kendrick extrication device'],
        'sam' => ['sam splint', 'splint'],
    ];
}
