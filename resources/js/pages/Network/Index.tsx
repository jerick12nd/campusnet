import { Head } from '@inertiajs/react';

interface OfficeStatus {
  office: string;
  cidr: string;
  online: number;
  randomMac: number;
  peak24h: number;
  average24h: number;
  scannedAt: string;
}

interface Props {
  offices: OfficeStatus[];
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleString('en-PH', {
    hour: 'numeric',
    minute: '2-digit',
    month: 'short',
    day: 'numeric',
  });
}

export default function Index({ offices }: Props) {
  return (
    <>
      <Head title="Online Devices per Office" />
      <div className="p-6">
        <h1 className="mb-1 text-xl font-semibold">Online Devices per Office</h1>
        <p className="mb-4 text-sm text-gray-500">
          Gikan sa pinakabag-o nga network scan. Ang numero nagpakita kung pila ang online, dili ang bandwidth usage.
        </p>

        {offices.length === 0 ? (
          <p className="text-sm text-gray-500">
            Wala pa'y scan. I-run ang <code>php artisan network:scan</code>.
          </p>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b">
                <th className="py-2">Office</th>
                <th>Subnet</th>
                <th>Online</th>
                <th>Phones (random MAC)</th>
                <th>Peak 24h</th>
                <th>Average 24h</th>
                <th>Last scan</th>
              </tr>
            </thead>
            <tbody>
              {offices.map((o) => (
                <tr key={o.office} className="border-b">
                  <td className="py-2 font-medium">{o.office}</td>
                  <td>{o.cidr}</td>
                  <td>{o.online}</td>
                  <td>{o.randomMac}</td>
                  <td>{o.peak24h}</td>
                  <td>{o.average24h}</td>
                  <td>{formatTime(o.scannedAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  );
}