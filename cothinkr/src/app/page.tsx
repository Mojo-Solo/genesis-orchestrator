'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';

export default function Home() {
  const router = useRouter();

  useEffect(() => {
    // Redirect to dashboard on load
    router.push('/dashboard');
  }, [router]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50">
      <div className="text-center">
        <div className="w-16 h-16 bg-brand-brown rounded-lg flex items-center justify-center mx-auto mb-4">
          <span className="text-white font-bold text-2xl">C</span>
        </div>
        <h1 className="text-2xl font-bold text-brand-ink mb-2">COTHINK&apos;R</h1>
        <p className="text-gray-600">Loading your strategic dashboard...</p>
      </div>
    </div>
  );
}
