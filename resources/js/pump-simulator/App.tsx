import React from 'react';
import { PumpProvider } from './stores/usePumpStore';
import PumpPanel from './components/PumpPanel';

const App: React.FC = () => {
  return (
    <PumpProvider>
      <PumpPanel />
    </PumpProvider>
  );
};

export default App;
