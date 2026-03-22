import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import LayoutPlanner from './components/LayoutPlanner';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5 * 60 * 1000,
      retry: 1,
    },
  },
});

const App: React.FC = () => {
  return (
    <QueryClientProvider client={queryClient}>
      <LayoutPlanner />
    </QueryClientProvider>
  );
};

export default App;