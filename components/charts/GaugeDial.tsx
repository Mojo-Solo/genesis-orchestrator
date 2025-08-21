"use client";

type Props = { label: string; value: number; min?: number; max?: number };

export function GaugeDial({ label, value, min = 0, max = 100 }: Props) {
  // Custom gauge using SVG
  const percentage = ((value - min) / (max - min)) * 100;
  const strokeDasharray = `${percentage}, 100`;

  return (
    <div className="flex flex-col items-center">
      <div className="relative grid place-items-center w-28 h-28">
        <svg className="-rotate-90" viewBox="0 0 36 36" width="112" height="112">
          <path 
            d="M18 2 a 16 16 0 1 1 0 32 a 16 16 0 1 1 0 -32" 
            fill="none" 
            stroke="#e5e7eb" 
            strokeWidth="4" 
          />
          <path 
            d="M18 2 a 16 16 0 1 1 0 32" 
            fill="none" 
            stroke="#8B5E3C" 
            strokeWidth="4" 
            strokeDasharray={strokeDasharray}
            className="transition-all duration-300 ease-out"
          />
        </svg>
        <div className="absolute text-xl font-semibold text-gray-800 dark:text-gray-200">
          {value.toFixed(1)}°
        </div>
      </div>
      <div className="mt-1 text-sm text-gray-600 dark:text-gray-400">{label}</div>
    </div>
  );
}