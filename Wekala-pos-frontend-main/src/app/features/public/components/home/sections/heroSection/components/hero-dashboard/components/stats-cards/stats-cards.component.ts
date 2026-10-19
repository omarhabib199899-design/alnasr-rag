import {
  ChangeDetectionStrategy,
  Component
} from '@angular/core';

import { StatCardComponent } from '../../../../../../../../../../shared/components/cards/stat-card/stat-card.component';
import { StatCardModel } from '../../../../../../../../../../shared/models/stat-card.model';

/**
 * Component displaying a summary collection of key performance indicator cards.
 */
@Component({
  selector: 'app-stats-cards',
  standalone: true,
  imports: [
    StatCardComponent
  ],
  templateUrl: './stats-cards.component.html',
  styleUrl: './stats-cards.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class StatsCardsComponent {

  // Collection of statistic models to be rendered in the dashboard
  readonly cards: StatCardModel[] = [
    {
      title: "Today's Sales",
      value: 24860,
      prefix: "EGP ",
      badge: "+18%"
    },
    {
      title: "Orders",
      value: 142,
      badge: "+9%"
    },
    {
      title: "Avg. Ticket",
      value: 175,
      prefix: "EGP ",
      badge: "+4%"
    }
  ];

}