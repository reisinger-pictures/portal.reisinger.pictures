import { FieldValues, UseFormRegister } from 'react-hook-form';

interface CheckboxItem {
    name: string;
    label: string;
    description: string;
}

interface CheckboxGroupProps<T extends FieldValues> {
    items: CheckboxItem[];
    register: UseFormRegister<T>;
}

export default function CheckboxGroup<T extends FieldValues>({ items, register }: CheckboxGroupProps<T>) {
    return (
        <>
            {items.map((item, i) => (
                <label key={item.name} className={`cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box border w-full hover:bg-base-300/50 transition-colors ${i === 0 ? 'border-primary/20' : 'border-base-300'}`}>
                    <input type="checkbox" className="checkbox checkbox-primary shrink-0" {...(register as (name: string) => ReturnType<typeof register>)(item.name)} />
                    <div>
                        <span className="label-text font-bold block">{item.label}</span>
                        <span className="label-text-alt opacity-70 leading-tight block mt-1">{item.description}</span>
                    </div>
                </label>
            ))}
        </>
    );
}
