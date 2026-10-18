import type { LocalField } from './types';

/**
 * M177 (`R-71509975`) — "Other, please specify": a choice question's `other` choice and the Short text shown only when it
 * is picked, linked by the choice question's `config.other_specify_key`. The server makes and removes the pair
 * (`App\Support\Forms\OtherSpecify`); the builder needs the condition's TEXT only to keep it naming the choice question
 * when that question's key is renamed, so it is restated here, exactly as `condition-model.ts` prints a structured row.
 */
export function otherSpecifyCondition(choiceKey: string): string {
    return `selected(\${${choiceKey}}, 'other')`;
}

/** The Please specify question a choice question's link names, or undefined when it names none or one that is gone. */
export function otherSpecifyChild(field: LocalField, all: readonly LocalField[]): LocalField | undefined {
    const key = field.config.other_specify_key;

    return typeof key === 'string' && key !== '' ? all.find((other) => other.key === key) : undefined;
}
