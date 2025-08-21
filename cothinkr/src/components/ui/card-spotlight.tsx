'use client';

import React, { useState, useRef, useEffect } from 'react';
import { cn } from '@/lib/utils';

interface CardSpotlightProps {
  children: React.ReactNode;
  className?: string;
  spotlightColor?: string;
  hoverEffect?: boolean;
}

const CardSpotlight: React.FC<CardSpotlightProps> = ({
  children,
  className = '',
  spotlightColor = '#8B5E3C',
  hoverEffect = true
}) => {
  const [mousePosition, setMousePosition] = useState({ x: 0, y: 0 });
  const [isHovering, setIsHovering] = useState(false);
  const cardRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleMouseMove = (event: MouseEvent) => {
      if (cardRef.current) {
        const rect = cardRef.current.getBoundingClientRect();
        setMousePosition({
          x: event.clientX - rect.left,
          y: event.clientY - rect.top,
        });
      }
    };

    const card = cardRef.current;
    if (card && hoverEffect) {
      card.addEventListener('mousemove', handleMouseMove);
      card.addEventListener('mouseenter', () => setIsHovering(true));
      card.addEventListener('mouseleave', () => setIsHovering(false));

      return () => {
        card.removeEventListener('mousemove', handleMouseMove);
        card.removeEventListener('mouseenter', () => setIsHovering(true));
        card.removeEventListener('mouseleave', () => setIsHovering(false));
      };
    }
  }, [hoverEffect]);

  return (
    <div
      ref={cardRef}
      className={cn(
        'relative overflow-hidden rounded-lg border bg-card text-card-foreground shadow-sm transition-all duration-300',
        hoverEffect && 'hover:shadow-lg hover:scale-[1.02]',
        className
      )}
    >
      {/* Spotlight effect */}
      {hoverEffect && (
        <div
          className={cn(
            'pointer-events-none absolute -inset-px rounded-lg opacity-0 transition-opacity duration-300',
            isHovering && 'opacity-100'
          )}
          style={{
            background: `radial-gradient(circle 100px at ${mousePosition.x}px ${mousePosition.y}px, ${spotlightColor}15, transparent 40%)`,
          }}
        />
      )}

      {/* Animated border */}
      {hoverEffect && (
        <div
          className={cn(
            'absolute inset-0 rounded-lg opacity-0 transition-opacity duration-300',
            isHovering && 'opacity-100'
          )}
          style={{
            background: `linear-gradient(45deg, ${spotlightColor}20, transparent, ${spotlightColor}20)`,
            backgroundSize: '200% 200%',
            animation: isHovering ? 'shimmer 2s ease-in-out infinite' : 'none',
          }}
        />
      )}

      {/* Content */}
      <div className="relative z-10">{children}</div>

      <style jsx>{`
        @keyframes shimmer {
          0% {
            background-position: 0% 50%;
          }
          50% {
            background-position: 100% 50%;
          }
          100% {
            background-position: 0% 50%;
          }
        }
      `}</style>
    </div>
  );
};

export default CardSpotlight;