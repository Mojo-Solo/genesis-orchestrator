'use client';

import React, { useState, useEffect } from 'react';
import { GaugeData } from '@/lib/types';
import { cn } from '@/lib/utils';

interface GaugeDialProps extends GaugeData {
  className?: string;
  size?: 'sm' | 'md' | 'lg';
  showAnimation?: boolean;
}

// NumberTicker component for animated numbers
const NumberTicker: React.FC<{
  value: number;
  unit?: string;
  className?: string;
}> = ({ value, unit = '', className = '' }) => {
  const [displayValue, setDisplayValue] = useState(0);
  
  useEffect(() => {
    const duration = 1500; // 1.5 seconds
    const steps = 60;
    const stepValue = value / steps;
    const stepDuration = duration / steps;
    
    let currentStep = 0;
    const interval = setInterval(() => {
      if (currentStep <= steps) {
        setDisplayValue(currentStep * stepValue);
        currentStep++;
      } else {
        setDisplayValue(value);
        clearInterval(interval);
      }
    }, stepDuration);
    
    return () => clearInterval(interval);
  }, [value]);
  
  return (
    <span className={className}>
      {displayValue.toFixed(1)}{unit}
    </span>
  );
};

// Enhanced gauge component with MagicUI-style animations
const EnhancedGauge: React.FC<GaugeDialProps> = ({ 
  label, 
  value, 
  min = 0, 
  max = 100, 
  unit = '%',
  className = '',
  size = 'md',
  showAnimation = true
}) => {
  const percentage = Math.min(Math.max(((value - min) / (max - min)) * 100, 0), 100);
  const angle = (percentage / 100) * 270; // 3/4 circle
  
  const sizeClasses = {
    sm: { container: 'w-20 h-16', svg: 'w-20 h-16', text: 'text-xs', radius: 30, stroke: 4 },
    md: { container: 'w-28 h-20', svg: 'w-28 h-20', text: 'text-sm', radius: 40, stroke: 6 },
    lg: { container: 'w-36 h-26', svg: 'w-36 h-26', text: 'text-base', radius: 50, stroke: 8 }
  };
  
  const config = sizeClasses[size];
  const center = config.radius + 10;
  const circumference = 2 * Math.PI * config.radius * 0.75; // 3/4 circle
  const strokeDasharray = `${circumference} ${circumference}`;
  const strokeDashoffset = circumference - (percentage / 100) * circumference;
  
  // Color based on percentage
  const getColor = (pct: number) => {
    if (pct >= 75) return '#10b981'; // green-500
    if (pct >= 50) return '#f59e0b'; // amber-500  
    if (pct >= 25) return '#ef4444'; // red-500
    return '#6b7280'; // gray-500
  };
  
  const color = getColor(percentage);
  
  return (
    <div className={cn('relative p-4 rounded-lg bg-gradient-to-br from-white to-gray-50 border shadow-sm', className)}>
      {/* Spotlight effect */}
      <div 
        className="absolute inset-0 rounded-lg opacity-30 bg-gradient-to-br from-brand-brown/20 via-transparent to-transparent"
        style={{
          background: `radial-gradient(circle at 30% 30%, ${color}20 0%, transparent 50%)`
        }}
      />
      
      <div className="relative flex flex-col items-center space-y-2">
        <div className="relative">
          <svg 
            width={config.svg.split('-')[1]} 
            height={config.svg.split('-')[1]} 
            viewBox={`0 0 ${(config.radius + 10) * 2} ${(config.radius + 10) * 2}`}
            className="transform -rotate-135"
          >
            {/* Background circle */}
            <circle
              cx={center}
              cy={center}
              r={config.radius}
              fill="none"
              stroke="rgb(229 231 235)"
              strokeWidth={config.stroke}
              strokeLinecap="round"
              strokeDasharray={strokeDasharray}
            />
            
            {/* Progress circle with animation */}
            <circle
              cx={center}
              cy={center}
              r={config.radius}
              fill="none"
              stroke={color}
              strokeWidth={config.stroke}
              strokeLinecap="round"
              strokeDasharray={strokeDasharray}
              strokeDashoffset={showAnimation ? strokeDashoffset : 0}
              className={showAnimation ? "transition-all duration-1500 ease-out" : ""}
              style={{
                filter: `drop-shadow(0 0 6px ${color}40)`
              }}
            />
            
            {/* Animated glow effect */}
            {showAnimation && (
              <circle
                cx={center}
                cy={center}
                r={config.radius - 2}
                fill="none"
                stroke={color}
                strokeWidth="1"
                opacity="0.3"
                className="animate-pulse"
              />
            )}
          </svg>
          
          {/* Center content */}
          <div className={cn(
            "absolute inset-0 flex flex-col items-center justify-center font-semibold",
            config.text
          )}>
            {showAnimation ? (
              <NumberTicker 
                value={value} 
                unit={unit}
                className="text-gray-900"
              />
            ) : (
              <span className="text-gray-900">
                {value.toFixed(1)}{unit}
              </span>
            )}
          </div>
        </div>
        
        <div className="text-xs font-medium text-gray-600 text-center max-w-24">
          {label}
        </div>
        
        {/* Progress indicator */}
        <div className="w-full max-w-20 h-1 bg-gray-200 rounded-full overflow-hidden">
          <div 
            className="h-full rounded-full transition-all duration-1500 ease-out"
            style={{ 
              width: `${percentage}%`,
              backgroundColor: color
            }}
          />
        </div>
      </div>
    </div>
  );
};

// Fallback gauge component using simple SVG
const FallbackGauge: React.FC<GaugeDialProps> = ({ 
  label, 
  value, 
  min = 0, 
  max = 100, 
  unit = '%',
  className = '',
  size = 'md'
}) => {
  const percentage = ((value - min) / (max - min)) * 100;
  const angle = (percentage / 100) * 180; // Half circle
  const radius = size === 'sm' ? 30 : size === 'lg' ? 50 : 40;
  const strokeWidth = size === 'sm' ? 4 : size === 'lg' ? 10 : 8;
  const center = 50;
  
  // Calculate arc path
  const startAngle = 180;
  const endAngle = startAngle + angle;
  const startAngleRad = (startAngle * Math.PI) / 180;
  const endAngleRad = (endAngle * Math.PI) / 180;
  
  const x1 = center + radius * Math.cos(startAngleRad);
  const y1 = center + radius * Math.sin(startAngleRad);
  const x2 = center + radius * Math.cos(endAngleRad);
  const y2 = center + radius * Math.sin(endAngleRad);
  
  const largeArcFlag = angle > 180 ? 1 : 0;
  const pathData = `M ${x1} ${y1} A ${radius} ${radius} 0 ${largeArcFlag} 1 ${x2} ${y2}`;
  
  return (
    <div className={cn('flex flex-col items-center space-y-2', className)}>
      <div className="relative">
        <svg width="100" height="70" viewBox="0 0 100 70" className="overflow-visible">
          <path
            d={`M 10 50 A 40 40 0 0 1 90 50`}
            fill="none"
            stroke="rgb(229 231 235)"
            strokeWidth={strokeWidth}
            strokeLinecap="round"
          />
          <path
            d={pathData}
            fill="none"
            stroke="#8B5E3C"
            strokeWidth={strokeWidth}
            strokeLinecap="round"
          />
          <text
            x="50"
            y="45"
            textAnchor="middle"
            className="text-sm font-semibold fill-gray-900"
          >
            {value.toFixed(1)}{unit}
          </text>
        </svg>
      </div>
      <div className="text-sm font-medium text-gray-700 text-center">
        {label}
      </div>
    </div>
  );
};

// Main component with MagicUI integration attempt
const GaugeDial: React.FC<GaugeDialProps> = (props) => {
  const [useMagicUI, setUseMagicUI] = useState(true);
  
  useEffect(() => {
    // Try to detect if MagicUI is available
    try {
      // This would be the actual MagicUI import in a real implementation
      // const magicui = require('magicui-mcp');
      setUseMagicUI(false); // For now, use enhanced version
    } catch (error) {
      setUseMagicUI(false);
    }
  }, []);
  
  // For now, always use the enhanced version
  // In a real implementation, you would check for MagicUI availability
  return <EnhancedGauge {...props} />;
};

export default GaugeDial;