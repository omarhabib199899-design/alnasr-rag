import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';
import { InfoCardComponent } from '../../../../../../../../shared/components/cards/info-card/info-card.component';

/**
 * Interface representing the details of the company that developed the software.
 */
interface CompanyItem {
  badge: string;
  title: string;
  leading: string;
}

@Component({
  selector: 'app-developed-company',
  standalone: true,
  imports: [
    InfoCardComponent
  ],
  templateUrl: './developed-company.component.html',
  styleUrl: './developed-company.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class DevelopedCompanyComponent {

  // Signal holding the information of the developing company
  readonly companies = signal<CompanyItem[]>([
    {
      badge: 'Developed By',
      title: 'Technique Software',
      leading: 'T'
    }
  ]);

}