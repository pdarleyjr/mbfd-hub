import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import StationCard from './StationCard';
import SummaryStrip from './SummaryStrip';
import { board, station } from './fixtures';
import { ageLabel, crewLine, readableName } from './format';

describe('StationCard', () => {
  it('shows crew, checkout progress per truck, requests and vehicle issues, and opens the station on click', async () => {
    const onOpen = vi.fn();
    render(<ul><StationCard station={station} onOpen={onOpen} /></ul>);

    expect(screen.getByText('3 working')).toBeInTheDocument();
    expect(screen.getByText('Jane Smith, John Doe, Sam Lee')).toBeInTheDocument();
    expect(screen.getByText('1 of 2')).toBeInTheDocument();
    expect(screen.getByText('E1').closest('li')).toHaveAttribute('data-state', 'submitted');
    expect(screen.getByText('L1').closest('li')).toHaveAttribute('data-state', 'not_submitted');
    expect(screen.getByText('2 open')).toBeInTheDocument();
    expect(screen.getByText(/1 vehicle with issues/)).toBeInTheDocument();
    expect(screen.getByText('Watch')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button'));
    expect(onOpen).toHaveBeenCalledTimes(1);
  });

  it('says the roster is not available yet instead of an empty crew', () => {
    render(<ul><StationCard station={{ ...station, crew: { available: false, message: 'Not yet', working_count: 0, away_count: 0, members: [] } }} onOpen={() => undefined} /></ul>);
    expect(screen.getByText('Roster not available yet')).toBeInTheDocument();
  });
});

describe('SummaryStrip', () => {
  it('reports the department in numbers', () => {
    render(<SummaryStrip summary={board.summary} rosterAvailable />);
    expect(screen.getByTestId('summary-checkouts')).toHaveTextContent('1 of 2');
    expect(screen.getByTestId('summary-checkouts')).toHaveTextContent('1 still to come');
    expect(screen.getByTestId('summary-requests')).toHaveTextContent('2');
    expect(screen.getByTestId('summary-vehicles')).toHaveTextContent('1');
  });
});

describe('format helpers', () => {
  it('reads roster names and ages', () => {
    expect(readableName("O'NEIL, MARY-ANN")).toBe("Mary-Ann O'Neil");
    expect(crewLine(station.crew.members, 2)).toBe('Jane Smith, John Doe +1 more');
    expect(ageLabel('2026-11-05T14:48:00Z', Date.parse('2026-11-05T15:00:00Z'))).toBe('12 min ago');
    expect(ageLabel(null)).toBe('');
  });
});
