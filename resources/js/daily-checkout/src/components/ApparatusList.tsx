import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import { ApiClient } from "../utils/api";

interface ApiApparatus {
  id: number;
  unit_id: string;
  make: string;
  model: string;
  year: number;
  status: string;
  mileage: number;
}

export default function ApparatusList() {
  const [apparatuses, setApparatuses] = useState<ApiApparatus[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchApparatuses = async () => {
      try {
        const data = await ApiClient.getApparatuses();
        setApparatuses(data);
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to load apparatuses");
      } finally {
        setLoading(false);
      }
    };

    fetchApparatuses();
  }, []);

  if (loading) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-blue-900 to-blue-700 flex items-center justify-center">
        <div className="text-white text-xl">Loading apparatuses...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-blue-900 to-blue-700 flex items-center justify-center">
        <div className="text-center text-white p-4">
          <p className="text-red-300">Error: {error}</p>
          <button
            onClick={() => window.location.reload()}
            className="mt-4 px-6 py-3 bg-white text-blue-900 rounded-lg font-semibold hover:bg-blue-100"
          >
            Retry
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-900 to-blue-700">
      <div className="max-w-6xl mx-auto py-12 px-4">
        <div className="text-center mb-12">
          <h1 className="text-4xl font-bold text-white mb-4">MBFD Daily Checkout</h1>
          <p className="text-blue-200 text-lg">Select an apparatus to begin the daily inspection</p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
          {apparatuses.map((apparatus) => (
            <Link
              key={apparatus.id}
              to={`/apparatus/${apparatus.id}`}
              className="block bg-white rounded-xl shadow-lg hover:shadow-2xl transition-all transform hover:-translate-y-1 overflow-hidden"
            >
              <div className="bg-red-600 text-white py-3 px-4">
                <h2 className="text-2xl font-bold text-center">{apparatus.unit_id}</h2>
              </div>
              <div className="p-6">
                <p className="text-gray-700 text-center mb-2 font-medium">
                  {apparatus.year} {apparatus.make}
                </p>
                <p className="text-gray-500 text-center text-sm mb-4">{apparatus.model}</p>
                <div className="flex justify-center">
                  <span className={`px-4 py-1 rounded-full text-sm font-medium ${
                    apparatus.status === "In Service" 
                      ? "bg-green-100 text-green-800"
                      : "bg-yellow-100 text-yellow-800"
                  }`}>
                    {apparatus.status}
                  </span>
                </div>
              </div>
            </Link>
          ))}
        </div>

        {apparatuses.length === 0 && (
          <div className="text-center text-blue-200 mt-12">
            No apparatuses available for inspection.
          </div>
        )}
      </div>
    </div>
  );
}